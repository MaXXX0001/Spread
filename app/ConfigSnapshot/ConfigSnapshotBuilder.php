<?php

namespace App\ConfigSnapshot;

use App\Models\Campaign;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Writes the redirect config snapshot described in docs/contract.md.
 */
final class ConfigSnapshotBuilder
{
    public const string CAMPAIGNS_KEY = 'spread:config:campaigns';

    public const string CAMPAIGNS_TMP_KEY = 'spread:config:campaigns:tmp';

    public const string META_KEY = 'spread:config:meta';

    private const int CONTRACT_VERSION = 1;

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @return int Number of campaigns in the snapshot.
     */
    public function rebuild(): int
    {
        $fallbackUrl = (string) config('spread.fallback_url');

        if ($fallbackUrl === '') {
            throw new RuntimeException('SPREAD_FALLBACK_URL is not set; the config snapshot was not rebuilt.');
        }

        $campaigns = Campaign::query()->with('offer')->orderBy('id')->get();
        $fields = [];

        foreach ($campaigns as $campaign) {
            $entry = [
                'id' => $campaign->id,
                'offer_id' => $campaign->offer_id,
                'active' => $campaign->active,
                'offer_url' => $campaign->offer->url_template,
            ];
            $fields[$campaign->alias] = json_encode($entry, self::JSON_FLAGS);
        }

        $now = now('UTC');
        $meta = [
            'v' => self::CONTRACT_VERSION,
            'version' => $now->getTimestampMs(),
            'generated_at' => $now->format('Y-m-d\TH:i:s.v\Z'),
            'fallback_url' => $fallbackUrl,
        ];
        $metaJson = json_encode($meta, self::JSON_FLAGS);

        // Building tmp inside MULTI keeps two concurrent rebuilds from mixing their fields.
        $results = Redis::connection('spread')->transaction(function (\Redis $redis) use ($fields, $metaJson): void {
            if ($fields === []) {
                // HSET without fields and RENAME of a missing key are Redis errors.
                $redis->del(self::CAMPAIGNS_KEY);
            } else {
                $redis->del(self::CAMPAIGNS_TMP_KEY);
                $redis->hSet(self::CAMPAIGNS_TMP_KEY, $fields);
                $redis->rename(self::CAMPAIGNS_TMP_KEY, self::CAMPAIGNS_KEY);
            }

            $redis->set(self::META_KEY, $metaJson);
        });

        // phpredis reports a failed command inside MULTI as false in the EXEC result instead of throwing.
        if (! is_array($results) || in_array(false, $results, true)) {
            throw new RuntimeException('Redis rejected the config snapshot transaction.');
        }

        return count($fields);
    }
}
