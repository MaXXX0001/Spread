<?php

namespace App\Postbacks;

use App\Models\CpaNetwork;
use App\Models\Postback;
use Illuminate\Database\QueryException;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RedisException;
use RuntimeException;
use Throwable;

/**
 * Moves postbacks from the `spread:postbacks` stream into the raw postback log and processes them (docs/contract.md).
 */
final class PostbackConsumer
{
    public const string STREAM_KEY = 'spread:postbacks';

    public const string DEAD_STREAM_KEY = 'spread:postbacks:dead';

    public const string GROUP = 'db';

    public const int DEFAULT_MIN_IDLE_MS = 60_000;

    private const int BATCH_SIZE = 100;

    private const int BLOCK_MS = 2000;

    private string $claimCursor = '0-0';

    public function __construct(
        public readonly string $consumer,
        public readonly int $minIdleMs = self::DEFAULT_MIN_IDLE_MS,
        private readonly PostbackProcessor $processor = new PostbackProcessor,
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
            Log::error('Postback batch failed; its messages stay pending.', ['exception' => $exception]);

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
     * The raw log is written first, so a bug in processing never loses a postback.
     *
     * @param  array<string, array<string, string>>  $messages  Stream id => fields.
     */
    private function process(array $messages): void
    {
        $parsed = [];
        $dead = [];

        foreach ($messages as $fields) {
            try {
                $parsed[] = PostbackMessageParser::parse($fields);
            } catch (InvalidPostbackMessage $exception) {
                $dead[] = [
                    'payload' => $fields['payload'] ?? '',
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        $this->storeRaw($parsed);

        foreach ($parsed as $message) {
            $this->processStored($message['postback_id']);
        }

        $redis = $this->redis();

        foreach ($dead as $entry) {
            $redis->xadd(self::DEAD_STREAM_KEY, '*', $entry);
        }

        $ids = array_keys($messages);
        $redis->xack(self::STREAM_KEY, self::GROUP, $ids);
        $redis->xdel(self::STREAM_KEY, $ids);
    }

    /**
     * The key is resolved to its network here and never stored.
     *
     * @param  list<array{postback_id: string, received_at: string, key: string, ip: ?string, params: string}>  $parsed
     */
    private function storeRaw(array $parsed): void
    {
        if ($parsed === []) {
            return;
        }

        $keyColumn = array_column($parsed, 'key');
        $keys = array_unique($keyColumn);
        $networkIdsByKey = CpaNetwork::query()
            ->whereIn('postback_key', $keys)
            ->pluck('id', 'postback_key')
            ->all();
        $createdAt = Carbon::now('UTC');
        $rows = [];

        foreach ($parsed as $message) {
            $rows[] = [
                'postback_id' => $message['postback_id'],
                'received_at' => $message['received_at'],
                'cpa_network_id' => $networkIdsByKey[$message['key']] ?? null,
                'ip' => $message['ip'],
                'params' => $message['params'],
                'state' => PostbackState::RECEIVED->value,
                'created_at' => $createdAt,
            ];
        }

        DB::table('postbacks')->insertOrIgnore($rows);
    }

    /**
     * An entry already processed on an earlier delivery is skipped; a failed one is tried again.
     */
    private function processStored(string $postbackId): void
    {
        $postback = Postback::query()->findOrFail($postbackId);
        $isPending = in_array($postback->state, [PostbackState::RECEIVED, PostbackState::FAILED], true);

        if (! $isPending) {
            return;
        }

        try {
            $this->processor->process($postback);
        } catch (Throwable $exception) {
            // When the database itself is down, this update throws too and the batch stays pending.
            $this->markFailed($postbackId, $exception);
        }
    }

    private function markFailed(string $postbackId, Throwable $exception): void
    {
        $exceptionClass = $exception::class;

        Postback::query()
            ->whereKey($postbackId)
            ->update([
                'state' => PostbackState::FAILED->value,
                'reason' => "{$exceptionClass}: {$exception->getMessage()}",
                'processed_at' => Carbon::now('UTC'),
            ]);
    }

    private function redis(): Connection
    {
        return Redis::connection('spread');
    }
}
