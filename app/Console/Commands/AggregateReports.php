<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Reports\StatsAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spread:reports:aggregate
    {--days=2 : Number of days up to and including today, in the report timezone}
    {--from= : First date, Y-m-d in the report timezone (overrides --days)}
    {--to= : Last date, Y-m-d in the report timezone (default: today, or --from)}
    {--all : Rebuild the stats for the whole time clicks exist}')]
#[Description('Recalculate hourly report stats from clicks and conversions')]
class AggregateReports extends Command
{
    private const string DATE_FORMAT = 'Y-m-d';

    private const string OUTPUT_FORMAT = 'Y-m-d H:i';

    /**
     * Execute the console command.
     */
    public function handle(StatsAggregator $aggregator): int
    {
        if ($this->option('all')) {
            $rows = $aggregator->rebuildAll();

            $this->info("Rebuilt all report stats: {$rows} rows.");

            return self::SUCCESS;
        }

        $timezone = Setting::current()->report_timezone;
        $today = CarbonImmutable::today($timezone);
        $from = $this->dateOption('from', $timezone);
        $to = $this->dateOption('to', $timezone);
        $days = (string) $this->option('days');
        $hasValidDays = ctype_digit($days) && (int) $days >= 1;

        if (
            $from === false
            || $to === false
        ) {
            $this->error('Dates must be in the Y-m-d format.');

            return self::FAILURE;
        }

        if (
            $from === null
            && $to === null
        ) {
            if (! $hasValidDays) {
                $this->error('--days must be a positive integer.');

                return self::FAILURE;
            }

            $from = $today->subDays((int) $days - 1);
        }

        $from ??= $to;
        $to ??= $from->max($today);

        if ($from->gt($to)) {
            $this->error('--from must not be after --to.');

            return self::FAILURE;
        }

        $fromUtc = $from->setTimezone('UTC');
        $dayAfterTo = $to->addDay();
        $toUtc = $dayAfterTo->setTimezone('UTC');
        $rows = $aggregator->aggregate($fromUtc, $toUtc);
        $fromOutput = $fromUtc->format(self::OUTPUT_FORMAT);
        $toOutput = $toUtc->format(self::OUTPUT_FORMAT);

        $this->info("Aggregated {$fromOutput} – {$toOutput} UTC: {$rows} rows.");

        return self::SUCCESS;
    }

    /**
     * @return CarbonImmutable|false|null null when the option is not given, false when it is invalid.
     */
    private function dateOption(string $name, string $timezone): CarbonImmutable|false|null
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $value, $timezone);

        // createFromFormat() rolls over out-of-range parts (month 13), so require an exact round trip.
        if (
            $date === false
            || $date->format(self::DATE_FORMAT) !== $value
        ) {
            return false;
        }

        return $date;
    }
}
