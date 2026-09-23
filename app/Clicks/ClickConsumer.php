<?php

namespace App\Clicks;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RedisException;
use RuntimeException;

/**
 * Moves clicks from the `spread:clicks` stream into the database, one batch per iteration (docs/contract.md).
 */
final class ClickConsumer
{
    public const string STREAM_KEY = 'spread:clicks';

    public const string DEAD_STREAM_KEY = 'spread:clicks:dead';

    public const string GROUP = 'db';

    public const int DEFAULT_MIN_IDLE_MS = 60_000;

    private const int BATCH_SIZE = 500;

    private const int BLOCK_MS = 2000;

    private const int DUPLICATE_WINDOW_SECONDS = 60;

    private string $claimCursor = '0-0';

    public function __construct(
        public readonly string $consumer,
        public readonly int $minIdleMs = self::DEFAULT_MIN_IDLE_MS,
        private readonly DeviceDetection $deviceDetection = new DeviceDetection,
        private readonly GeoIp $geoIp = new GeoIp,
    ) {}

    public function createGroup(): void
    {
        $redis = $this->redis();
        $created = $redis->xgroup('CREATE', self::STREAM_KEY, self::GROUP, '0', true);

        if ($created !== false) {
            return;
        }

        $client = $redis->client();
        $error = (string) $client->getLastError();
        $client->clearLastError();

        if (! str_starts_with($error, 'BUSYGROUP')) {
            throw new RuntimeException("Cannot create consumer group: {$error}");
        }
    }

    /**
     * Messages of a failed batch stay pending and come back through XAUTOCLAIM.
     *
     * @return bool false when the batch failed on the database or Redis and the caller should pause.
     */
    public function iterate(): bool
    {
        try {
            $messages = $this->claimStale();

            if ($messages === []) {
                $messages = $this->readNew();
            }

            if ($messages !== []) {
                $this->process($messages);
            }
        } catch (QueryException|RedisException $exception) {
            Log::error('Click batch failed; its messages stay pending.', ['exception' => $exception]);

            return false;
        }

        return true;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function claimStale(): array
    {
        $redis = $this->redis();
        [$nextCursor, $messages, $deletedIds] = $redis->xautoclaim(
            self::STREAM_KEY,
            self::GROUP,
            $this->consumer,
            $this->minIdleMs,
            $this->claimCursor,
            self::BATCH_SIZE,
        );
        $this->claimCursor = $nextCursor;

        if ($deletedIds !== []) {
            $redis->xack(self::STREAM_KEY, self::GROUP, $deletedIds);
        }

        return $messages;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function readNew(): array
    {
        $result = $this->redis()->xreadgroup(
            self::GROUP,
            $this->consumer,
            [self::STREAM_KEY => '>'],
            self::BATCH_SIZE,
            self::BLOCK_MS,
        );

        return is_array($result) ? $result[self::STREAM_KEY] ?? [] : [];
    }

    /**
     * @param  array<string, array<string, string>>  $messages  Stream id => fields.
     */
    private function process(array $messages): void
    {
        $rows = [];
        $dead = [];
        $devicesByUserAgent = [];
        $createdAt = now('UTC');

        foreach ($messages as $id => $fields) {
            try {
                $row = ClickMessageParser::parse($fields);
            } catch (InvalidClickMessage $exception) {
                $dead[] = [
                    'payload' => $fields['payload'] ?? '',
                    'reason' => $exception->getMessage(),
                ];

                continue;
            }

            $userAgent = $row['user_agent'] ?? '';
            $devicesByUserAgent[$userAgent] ??= $this->deviceDetection->detect($userAgent);
            $row += $devicesByUserAgent[$userAgent];
            $row['country_code'] = $this->geoIp->country($row['ip']);
            $row['created_at'] = $createdAt;
            $rows[] = $row;
        }

        DB::table('clicks')->insertOrIgnoreReturning($rows, ['click_id'], ['click_id']);
        $this->flagDuplicates($rows);

        $redis = $this->redis();

        foreach ($dead as $entry) {
            $redis->xadd(self::DEAD_STREAM_KEY, '*', $entry);
        }

        $ids = array_keys($messages);
        $redis->xack(self::STREAM_KEY, self::GROUP, $ids);
        $redis->xdel(self::STREAM_KEY, $ids);
    }

    /**
     * Flags every click that has an earlier click with the same IP and User-Agent within the window.
     *
     * Candidates reach one window past the batch, so a later click stored before a delayed earlier one
     * (redelivery, XAUTOCLAIM) is flagged too. All batch rows count, including ones already stored, so a
     * batch that failed after its insert is flagged on redelivery. Clicks without an IP are never flagged.
     *
     * @param  list<array{clicked_at: string, ip: ?string}>  $rows
     */
    private function flagDuplicates(array $rows): void
    {
        $ipRows = array_filter($rows, fn (array $row): bool => $row['ip'] !== null);

        if ($ipRows === []) {
            return;
        }

        $ipColumn = array_column($ipRows, 'ip');
        $ips = array_unique($ipColumn);
        $clickedAts = array_column($ipRows, 'clicked_at');
        $from = min($clickedAts);
        $latest = max($clickedAts);
        $to = CarbonImmutable::parse($latest, 'UTC')
            ->addSeconds(self::DUPLICATE_WINDOW_SECONDS)
            ->format('Y-m-d H:i:s.v');

        DB::table('clicks as c')
            ->where('c.is_duplicate', '=', false)
            ->whereIn('c.ip', $ips)
            ->whereBetween('c.clicked_at', [$from, $to])
            ->whereExists(fn (Builder $query): Builder => $query
                ->from('clicks as p')
                ->whereColumn('p.ip', '=', 'c.ip')
                ->whereRaw('p.user_agent is not distinct from c.user_agent')
                ->whereRaw('p.clicked_at >= c.clicked_at - make_interval(secs => ?)', [self::DUPLICATE_WINDOW_SECONDS])
                ->whereColumn('p.clicked_at', '<=', 'c.clicked_at')
                // Earlier click: older timestamp, or the same millisecond and a smaller click_id.
                ->whereRaw('(p.clicked_at, p.click_id) < (c.clicked_at, c.click_id)'))
            ->update(['is_duplicate' => true]);
    }

    private function redis(): Connection
    {
        return Redis::connection('spread');
    }
}
