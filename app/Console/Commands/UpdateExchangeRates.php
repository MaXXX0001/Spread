<?php

namespace App\Console\Commands;

use App\Models\ExchangeRate;
use App\Money\NbuClient;
use App\Money\NbuUnavailable;
use App\Money\Recalculator;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Signature('spread:rates:update
    {--from= : First date, Y-m-d (default: today, UTC)}
    {--to= : Last date, Y-m-d (default: tomorrow, UTC, or --from)}')]
#[Description('Download official NBU exchange rates; manually entered rates are kept')]
class UpdateExchangeRates extends Command
{
    private const string DATE_FORMAT = 'Y-m-d';

    /**
     * Execute the console command.
     */
    public function handle(NbuClient $client, Recalculator $recalculator): int
    {
        $from = $this->dateOption('from');
        $to = $this->dateOption('to');

        if (
            $from === false
            || $to === false
        ) {
            $this->error('Dates must be in the Y-m-d format.');

            return self::FAILURE;
        }

        // NBU publishes tomorrow's rates during the day, so the default run fetches both days.
        if (
            $from === null
            && $to === null
        ) {
            $from = CarbonImmutable::today('UTC');
            $to = $from->addDay();
        }

        $from ??= $to;
        $to ??= $from;

        if ($from->gt($to)) {
            $this->error('--from must not be after --to.');

            return self::FAILURE;
        }

        $period = CarbonPeriod::create($from, $to);
        $hasSavedRates = false;

        foreach ($period as $day) {
            $date = CarbonImmutable::instance($day);
            $formattedDate = $date->format(self::DATE_FORMAT);

            try {
                $rates = $client->fetch($date);
            } catch (NbuUnavailable $exception) {
                $this->error("Rates for {$formattedDate} not updated: {$exception->getMessage()}");

                // Dates saved before the failure still fill the amounts that waited for them.
                if ($hasSavedRates) {
                    $this->recalculateMissing($recalculator);
                }

                return self::FAILURE;
            }

            if ($rates === []) {
                $this->line("{$formattedDate}: NBU has not published rates yet.");

                continue;
            }

            [$saved, $kept] = $this->store($date, $rates);
            $hasSavedRates = $hasSavedRates || $saved > 0;
            $this->info("{$formattedDate}: {$saved} rates saved, {$kept} manual kept.");
        }

        if ($hasSavedRates) {
            $this->recalculateMissing($recalculator);
        }

        return self::SUCCESS;
    }

    private function recalculateMissing(Recalculator $recalculator): void
    {
        [$costs, $conversions] = $recalculator->missing();

        $this->info("Recalculated {$costs} costs and {$conversions} conversions without a base amount.");
    }

    /**
     * @return CarbonImmutable|false|null null when the option is not given, false when it is invalid.
     */
    private function dateOption(string $name): CarbonImmutable|false|null
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!'.self::DATE_FORMAT, $value, 'UTC');

        // createFromFormat() rolls over out-of-range parts (month 13), so require an exact round trip.
        if (
            $date === false
            || $date->format(self::DATE_FORMAT) !== $value
        ) {
            return false;
        }

        return $date;
    }

    /**
     * @param  array<string, string>  $rates
     * @return array{int, int} Saved NBU rates and manual rates left untouched.
     */
    private function store(CarbonImmutable $date, array $rates): array
    {
        $formattedDate = $date->format(self::DATE_FORMAT);

        return DB::transaction(function () use ($formattedDate, $rates): array {
            $manualCurrencies = ExchangeRate::query()
                ->where('date', '=', $formattedDate)
                ->where('source', '=', ExchangeRate::SOURCE_MANUAL)
                ->pluck('currency')
                ->all();
            $now = Carbon::now('UTC');
            $rows = [];

            foreach ($rates as $currency => $rate) {
                if (in_array($currency, $manualCurrencies, true)) {
                    continue;
                }

                $rows[] = [
                    'date' => $formattedDate,
                    'currency' => $currency,
                    'uah_per_unit' => $rate,
                    'source' => ExchangeRate::SOURCE_NBU,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('exchange_rates')->upsert($rows, ['currency', 'date'], ['uah_per_unit', 'source', 'updated_at']);

            $keptCount = count($rates) - count($rows);

            return [count($rows), $keptCount];
        });
    }
}
