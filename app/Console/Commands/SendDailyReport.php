<?php

namespace App\Console\Commands;

use App\Alerts\DailyReport;
use App\Alerts\TelegramClient;
use App\Alerts\TelegramUnavailable;
use App\Models\AlertSetting;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spread:alerts:daily {--now : Send yesterday\'s report right away, whatever the time}')]
#[Description('Send the daily Telegram report about yesterday once its time has come')]
class SendDailyReport extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DailyReport $report, TelegramClient $telegram): int
    {
        $settings = AlertSetting::current();
        $timezone = Setting::current()->report_timezone;
        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        $isManual = (bool) $this->option('now');

        if (! $isManual) {
            // HH:MM strings compare correctly as text.
            $isTime = $now->format('H:i') >= $settings->daily_time;
            $sentOn = $settings->daily_sent_on?->format('Y-m-d');
            $isSentToday = $sentOn === $today->format('Y-m-d');

            if (
                ! $settings->daily_enabled
                || ! $isTime
                || $isSentToday
            ) {
                return self::SUCCESS;
            }
        }

        $yesterday = $today->subDay();
        $text = $report->text($yesterday);

        try {
            $telegram->send($text);
        } catch (TelegramUnavailable $exception) {
            $this->error("Daily report not sent: {$exception->getMessage()}");

            return self::FAILURE;
        }

        // A manual run does not count, so it never cancels the scheduled report.
        if (! $isManual) {
            $settings->daily_sent_on = $today->format('Y-m-d');
            $settings->save();
        }

        $this->info("Daily report for {$yesterday->format('Y-m-d')} sent.");

        return self::SUCCESS;
    }
}
