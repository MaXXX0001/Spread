<?php

namespace App\Alerts;

use App\Models\AlertSetting;
use App\Models\AlertState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns the current problems of each check into messages: the first one, reminders and "resolved".
 */
class AlertEngine
{
    public function __construct(
        private readonly TelegramClient $telegram,
    ) {}

    /**
     * @param  iterable<Check>  $checks
     */
    public function run(AlertSetting $settings, iterable $checks): void
    {
        foreach ($checks as $check) {
            $type = $check->type();

            if (! $type->isEnabled($settings)) {
                AlertState::query()->where('type', '=', $type)->delete();

                continue;
            }

            try {
                $problems = $check->problems($settings);
            } catch (Throwable $exception) {
                // Keep the open states as they are: a failed check proves nothing was resolved.
                Log::error("Alert check {$type->value} failed: {$exception->getMessage()}");

                continue;
            }

            $this->resolveGone($type, $problems);
            $this->notifyCurrent($type, $problems, $settings);
        }
    }

    /**
     * @param  array<string, string>  $problems
     */
    private function resolveGone(AlertType $type, array $problems): void
    {
        $gone = AlertState::query()
            ->where('type', '=', $type)
            ->whereNotIn('subject', array_keys($problems))
            ->get();

        foreach ($gone as $state) {
            // Nobody heard about a problem that was never delivered, so there is nothing to resolve.
            if ($state->last_sent_at === null) {
                $state->delete();

                continue;
            }

            $text = "✅ <b>Resolved: {$type->title()}</b>\n{$state->message}";

            if ($this->deliver($text)) {
                $state->delete();
            }
        }
    }

    /**
     * @param  array<string, string>  $problems
     */
    private function notifyCurrent(AlertType $type, array $problems, AlertSetting $settings): void
    {
        $now = CarbonImmutable::now();
        $remindBefore = $now->subHours($settings->reminder_hours);

        foreach ($problems as $subject => $message) {
            $state = AlertState::query()->firstOrNew(
                ['type' => $type, 'subject' => $subject],
                ['opened_at' => $now],
            );
            $state->message = $message;
            $isFirst = $state->last_sent_at === null;
            $isReminderDue = ! $isFirst && $state->last_sent_at->lte($remindBefore);

            if ($isFirst) {
                $text = "🔴 <b>{$type->title()}</b>\n{$message}";
            } elseif ($isReminderDue) {
                $duration = self::duration($state->opened_at, $now);
                $text = "🟠 <b>Still: {$type->title()}</b> (for {$duration})\n{$message}";
            } else {
                $text = null;
            }

            if (
                $text !== null
                && $this->deliver($text)
            ) {
                $state->last_sent_at = $now;
            }

            $state->save();
        }
    }

    private function deliver(string $text): bool
    {
        try {
            $this->telegram->send($text);
        } catch (TelegramUnavailable $exception) {
            Log::warning("Alert not delivered, retrying on the next check: {$exception->getMessage()}");

            return false;
        }

        return true;
    }

    private static function duration(CarbonImmutable $from, CarbonImmutable $to): string
    {
        $minutes = (int) $from->diffInMinutes($to);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $hours > 0 ? "{$hours}h {$rest}m" : "{$rest}m";
    }
}
