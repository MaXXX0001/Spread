<?php

namespace App\Money;

use App\Models\Conversion;
use App\Models\Cost;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recalculates the base currency amounts of costs and conversion payouts after rates or the base currency change.
 */
final class Recalculator
{
    private const int CHUNK_SIZE = 500;

    /**
     * How far after its date a rate can still be picked as "the latest earlier one" (see CurrencyConverter).
     */
    private const int RATE_REACH_DAYS = 7;

    /**
     * Amounts without a base amount, or computed for another base currency.
     *
     * @return array{int, int} Recalculated costs and conversions.
     */
    public function missing(): array
    {
        $baseCurrency = Setting::current()->base_currency;
        $isStale = fn (Builder $query, string $column): Builder => $query
            ->where(fn (Builder $query): Builder => $query
                ->whereNull($column)
                ->orWhere('base_currency', '<>', $baseCurrency)
                ->orWhereNull('base_currency'));
        $costs = $isStale(Cost::query(), 'amount_base');
        $conversions = $isStale($this->conversionsWithPayout(), 'payout_base');

        return $this->recalculate($costs, $conversions);
    }

    /**
     * @return array{int, int} Recalculated costs and conversions.
     */
    public function all(): array
    {
        $costs = Cost::query();
        $conversions = $this->conversionsWithPayout();

        return $this->recalculate($costs, $conversions);
    }

    /**
     * Amounts the rate of $currency on $date could have been picked for; all currencies when it is the base currency.
     *
     * @return array{int, int} Recalculated costs and conversions.
     */
    public function forRate(string $currency, CarbonImmutable $date): array
    {
        $setting = Setting::current();
        $isBaseRate = $currency === $setting->base_currency;
        $lastDate = $date->addDays(self::RATE_REACH_DAYS);
        $costs = Cost::query()
            ->whereBetween('date', [$date->toDateString(), $lastDate->toDateString()])
            ->when(! $isBaseRate, fn (Builder $query): Builder => $query->where('currency', '=', $currency));
        // Conversions are converted by the day of their status change in the report timezone.
        $localStart = CarbonImmutable::parse($date->toDateString(), $setting->report_timezone);
        $localLastDay = CarbonImmutable::parse($lastDate->toDateString(), $setting->report_timezone);
        $windowStart = $localStart->utc();
        $windowEnd = $localLastDay->endOfDay()->utc();
        $conversions = $this->conversionsWithPayout()
            ->whereBetween('status_changed_at', [$windowStart, $windowEnd])
            ->when(! $isBaseRate, fn (Builder $query): Builder => $query
                ->where(fn (Builder $query): Builder => $query
                    ->where('currency', '=', $currency)
                    ->orWhere(fn (Builder $query): Builder => $query
                        ->whereNull('currency')
                        ->whereHas('cpaNetwork', fn (Builder $query): Builder => $query->where('default_currency', '=', $currency)))));

        return $this->recalculate($costs, $conversions);
    }

    /**
     * @return Builder<Conversion>
     */
    private function conversionsWithPayout(): Builder
    {
        return Conversion::query()->whereNotNull('payout');
    }

    /**
     * Saved quietly: the saving hooks would convert each model a second time with a fresh converter.
     *
     * @param  Builder<Cost>  $costs
     * @param  Builder<Conversion>  $conversions
     * @return array{int, int}
     */
    private function recalculate(Builder $costs, Builder $conversions): array
    {
        $converter = new CurrencyConverter;
        $costCount = 0;
        $conversionCount = 0;

        $costs->chunkById(self::CHUNK_SIZE, function (Collection $chunk) use ($converter, &$costCount): void {
            foreach ($chunk as $cost) {
                $cost->convertToBase($converter);
                $cost->saveQuietly();
                $costCount++;
            }
        });

        $conversions->with('cpaNetwork')->chunkById(self::CHUNK_SIZE, function (Collection $chunk) use ($converter, &$conversionCount): void {
            foreach ($chunk as $conversion) {
                $conversion->convertPayoutToBase($converter);
                $conversion->saveQuietly();
                $conversionCount++;
            }
        });

        return [$costCount, $conversionCount];
    }
}
