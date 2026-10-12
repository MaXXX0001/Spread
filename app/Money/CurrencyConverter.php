<?php

namespace App\Money;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;

/**
 * Converts amounts between currencies through the hryvnia, by the rate on a date.
 *
 * Rates are cached for the lifetime of the instance, so create one per operation (a save, a recalculation run).
 */
final class CurrencyConverter
{
    private const string HRYVNIA = 'UAH';

    private const int SCALE = 12;

    private const int RESULT_SCALE = 4;

    private const int LOOKBACK_DAYS = 7;

    /**
     * @var array<string, ?string> "currency|date" => hryvnias per unit.
     */
    private array $rateCache = [];

    /**
     * @return ?string The amount in $baseCurrency with 4 decimals, or null when a rate is missing.
     */
    public function toBase(string $amount, string $currency, CarbonImmutable $date, string $baseCurrency): ?string
    {
        if ($currency === $baseCurrency) {
            return bcround($amount, self::RESULT_SCALE);
        }

        $rate = $this->uahPerUnit($currency, $date);
        $baseRate = $this->uahPerUnit($baseCurrency, $date);

        if (
            $rate === null
            || $baseRate === null
        ) {
            return null;
        }

        $inHryvnias = bcmul($amount, $rate, self::SCALE);
        $inBase = bcdiv($inHryvnias, $baseRate, self::SCALE);

        return bcround($inBase, self::RESULT_SCALE);
    }

    /**
     * The rate on the date, else the latest one of the previous days within the lookback window.
     */
    private function uahPerUnit(string $currency, CarbonImmutable $date): ?string
    {
        if ($currency === self::HRYVNIA) {
            return '1';
        }

        $formattedDate = $date->toDateString();
        $cacheKey = "{$currency}|{$formattedDate}";

        if (array_key_exists($cacheKey, $this->rateCache)) {
            return $this->rateCache[$cacheKey];
        }

        $earliestDate = $date->subDays(self::LOOKBACK_DAYS);
        $rate = ExchangeRate::query()
            ->where('currency', '=', $currency)
            ->where('date', '<=', $formattedDate)
            ->where('date', '>=', $earliestDate->toDateString())
            ->orderByDesc('date')
            ->value('uah_per_unit');
        $this->rateCache[$cacheKey] = $rate === null ? null : (string) $rate;

        return $this->rateCache[$cacheKey];
    }
}
