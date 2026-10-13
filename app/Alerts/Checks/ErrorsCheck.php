<?php

namespace App\Alerts\Checks;

use App\Alerts\AlertType;
use App\Alerts\Check;
use App\Clicks\ClickConsumer;
use App\Models\AlertSetting;
use App\Postbacks\PostbackConsumer;
use App\Postbacks\PostbackState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Postbacks that failed to process and messages set aside as invalid in the last minutes.
 */
class ErrorsCheck implements Check
{
    private const int WINDOW_MINUTES = 15;

    private const array DEAD_STREAMS = [
        ClickConsumer::DEAD_STREAM_KEY,
        PostbackConsumer::DEAD_STREAM_KEY,
    ];

    public function type(): AlertType
    {
        return AlertType::ERRORS;
    }

    public function problems(AlertSetting $settings): array
    {
        $since = CarbonImmutable::now()->subMinutes(self::WINDOW_MINUTES);
        $window = self::WINDOW_MINUTES;
        $problems = [];

        $failed = DB::table('postbacks')
            ->where('state', '=', PostbackState::FAILED->value)
            ->where('processed_at', '>=', $since->utc())
            ->count();

        if ($failed > 0) {
            $problems['postbacks'] = "{$failed} postbacks failed to process in the last {$window} min\nSee Postbacks, then replay them with <code>spread:postbacks:replay</code>.";
        }

        // Stream entry IDs start with their time in milliseconds.
        $redis = Redis::connection('spread')->client();
        $sinceId = (string) $since->getTimestampMs();
        $invalid = [];

        foreach (self::DEAD_STREAMS as $stream) {
            $entries = $redis->xRange($stream, $sinceId, '+');
            $count = count($entries);

            if ($count > 0) {
                $invalid[] = "{$count} in <b>{$stream}</b>";
            }
        }

        if ($invalid !== []) {
            $list = implode(', ', $invalid);
            $problems['dead'] = "Invalid messages in the last {$window} min: {$list}";
        }

        return $problems;
    }
}
