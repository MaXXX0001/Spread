<?php

namespace App\Money;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Official National Bank of Ukraine exchange rates: hryvnias per one unit of each currency.
 */
class NbuClient
{
    private const string URL = 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange';

    private const int TIMEOUT_SECONDS = 10;

    private const string CURRENCY_PATTERN = '/^[A-Z]{3}$/';

    /**
     * An empty array means the NBU has not published rates for the date yet.
     *
     * @return array<string, string> Currency => hryvnias per unit, 8 decimals.
     *
     * @throws NbuUnavailable
     */
    public function fetch(CarbonImmutable $date): array
    {
        $formattedDate = $date->format('Ymd');
        $url = self::URL."?date={$formattedDate}&json";

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->get($url);
        } catch (ConnectionException $exception) {
            throw new NbuUnavailable("NBU is unreachable: {$exception->getMessage()}");
        }

        if ($response->failed()) {
            throw new NbuUnavailable("NBU answered HTTP {$response->status()}.");
        }

        $items = $response->json();

        if (! is_array($items)) {
            throw new NbuUnavailable('NBU answered with something other than a JSON list.');
        }

        $rates = [];

        foreach ($items as $item) {
            $currency = $item['cc'] ?? null;
            $rate = $item['rate'] ?? null;

            $isValidCurrency = is_string($currency) && preg_match(self::CURRENCY_PATTERN, $currency) === 1;
            $isValidRate = (is_int($rate) || is_float($rate)) && $rate > 0;

            if (
                ! $isValidCurrency
                || ! $isValidRate
            ) {
                throw new NbuUnavailable('NBU answered with an invalid rate entry.');
            }

            // NBU rates have at most 5 decimals, so 8 decimals reproduce the float exactly.
            $rates[$currency] = sprintf('%.8F', $rate);
        }

        return $rates;
    }
}
