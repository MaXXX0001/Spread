<?php

namespace App\Alerts\Checks;

use App\Alerts\AlertType;
use App\Alerts\Check;
use App\Clicks\ClickConsumer;
use App\Models\AlertSetting;
use App\Postbacks\PostbackConsumer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

/**
 * Clicks or postbacks wait in Redis for too long or pile up: a worker is stopped or falls behind.
 */
class BufferCheck implements Check
{
    /**
     * Stream => the command that drains it.
     */
    private const array STREAMS = [
        ClickConsumer::STREAM_KEY => 'spread:clicks:consume',
        PostbackConsumer::STREAM_KEY => 'spread:postbacks:consume',
    ];

    public function type(): AlertType
    {
        return AlertType::BUFFER;
    }

    public function problems(AlertSetting $settings): array
    {
        // Workers delete what they processed, so the first entry is the oldest unprocessed one.
        $redis = Redis::connection('spread')->client();
        $nowMs = CarbonImmutable::now()->getTimestampMs();
        $problems = [];

        foreach (self::STREAMS as $stream => $worker) {
            $length = (int) $redis->xLen($stream);

            if ($length === 0) {
                continue;
            }

            $oldest = $redis->xRange($stream, '-', '+', 1);
            $oldestId = (string) array_key_first($oldest);
            $oldestMs = (int) explode('-', $oldestId)[0];
            $ageMinutes = intdiv($nowMs - $oldestMs, 60_000);
            $isStale = $ageMinutes >= $settings->buffer_max_age_minutes;
            $isOverflowing = $length > $settings->buffer_max_length;

            if (
                $isStale
                || $isOverflowing
            ) {
                $problems[$stream] = "<b>{$stream}</b>: {$length} messages waiting, the oldest for {$ageMinutes} min\nIs <code>{$worker}</code> running?";
            }
        }

        return $problems;
    }
}
