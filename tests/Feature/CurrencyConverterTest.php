<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Money\CurrencyConverter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyConverterTest extends TestCase
{
    use RefreshDatabase;

    private CurrencyConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->addRate('USD', '2026-10-10', '44.8526');
        $this->addRate('EUR', '2026-10-10', '50.1385');
        $this->converter = new CurrencyConverter;
    }

    public function test_eur_to_usd(): void
    {
        $converted = $this->convert('100', 'EUR', '2026-10-10', 'USD');

        $this->assertSame('111.7850', $converted);
    }

    public function test_uah_to_usd(): void
    {
        $converted = $this->convert('1000', 'UAH', '2026-10-10', 'USD');

        $this->assertSame('22.2953', $converted);
    }

    public function test_same_currency_is_unchanged(): void
    {
        $converted = $this->convert('25.5', 'USD', '2026-10-10', 'USD');

        $this->assertSame('25.5000', $converted);
    }

    public function test_hryvnia_as_base(): void
    {
        $converted = $this->convert('10', 'USD', '2026-10-10', 'UAH');

        $this->assertSame('448.5260', $converted);
    }

    public function test_rate_of_an_earlier_day_is_used(): void
    {
        $converted = $this->convert('100', 'EUR', '2026-10-12', 'USD');

        $this->assertSame('111.7850', $converted);
    }

    public function test_latest_earlier_rate_wins(): void
    {
        $this->addRate('EUR', '2026-10-08', '40');

        $converted = $this->convert('100', 'EUR', '2026-10-11', 'USD');

        $this->assertSame('111.7850', $converted);
    }

    public function test_rate_older_than_seven_days_is_not_used(): void
    {
        $withinWindow = $this->convert('100', 'EUR', '2026-10-17', 'USD');
        $tooOld = $this->convert('100', 'EUR', '2026-10-18', 'USD');

        $this->assertSame('111.7850', $withinWindow);
        $this->assertNull($tooOld);
    }

    public function test_later_rate_is_not_used(): void
    {
        $converted = $this->convert('100', 'EUR', '2026-10-09', 'USD');

        $this->assertNull($converted);
    }

    public function test_missing_base_rate_gives_null(): void
    {
        $converted = $this->convert('100', 'EUR', '2026-10-10', 'PLN');

        $this->assertNull($converted);
    }

    public function test_rounding_is_half_up(): void
    {
        $this->addRate('XXX', '2026-10-10', '1');
        $this->addRate('YYY', '2026-10-10', '1');

        $roundedUp = $this->convert('1.00005', 'XXX', '2026-10-10', 'YYY');
        $roundedDown = $this->convert('1.000049', 'XXX', '2026-10-10', 'YYY');

        $this->assertSame('1.0001', $roundedUp);
        $this->assertSame('1.0000', $roundedDown);
    }

    private function convert(string $amount, string $currency, string $date, string $baseCurrency): ?string
    {
        $day = CarbonImmutable::parse($date, 'UTC');

        return $this->converter->toBase($amount, $currency, $day, $baseCurrency);
    }

    private function addRate(string $currency, string $date, string $rate): void
    {
        ExchangeRate::create([
            'date' => $date,
            'currency' => $currency,
            'uah_per_unit' => $rate,
            'source' => ExchangeRate::SOURCE_NBU,
        ]);
    }
}
