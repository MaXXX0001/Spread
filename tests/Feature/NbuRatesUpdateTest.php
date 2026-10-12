<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NbuRatesUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const string NBU_URL = 'bank.gov.ua/NBUStatService/v1/statdirectory/exchange*';

    public function test_rates_of_a_day_are_saved(): void
    {
        Http::fake([self::NBU_URL => Http::response($this->nbuRates('10.10.2026'))]);

        $this->artisan('spread:rates:update', ['--from' => '2026-10-10', '--to' => '2026-10-10'])
            ->expectsOutputToContain('2026-10-10: 3 rates saved, 0 manual kept.')
            ->assertSuccessful();

        $this->assertRate('USD', '2026-10-10', '44.85260000', ExchangeRate::SOURCE_NBU);
        $this->assertRate('EUR', '2026-10-10', '50.13850000', ExchangeRate::SOURCE_NBU);
        $this->assertRate('JPY', '2026-10-10', '0.28341000', ExchangeRate::SOURCE_NBU);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'date=20261010&json'));
    }

    public function test_repeated_run_does_not_duplicate(): void
    {
        Http::fake([self::NBU_URL => Http::response($this->nbuRates('10.10.2026'))]);

        $this->artisan('spread:rates:update', ['--from' => '2026-10-10'])->assertSuccessful();
        $this->artisan('spread:rates:update', ['--from' => '2026-10-10'])->assertSuccessful();

        $this->assertSame(3, ExchangeRate::query()->count());
    }

    public function test_manual_rate_is_kept(): void
    {
        ExchangeRate::create([
            'date' => '2026-10-10',
            'currency' => 'EUR',
            'uah_per_unit' => '50.00',
            'source' => ExchangeRate::SOURCE_MANUAL,
        ]);
        Http::fake([self::NBU_URL => Http::response($this->nbuRates('10.10.2026'))]);

        $this->artisan('spread:rates:update', ['--from' => '2026-10-10'])
            ->expectsOutputToContain('2 rates saved, 1 manual kept.')
            ->assertSuccessful();

        $this->assertRate('EUR', '2026-10-10', '50.00000000', ExchangeRate::SOURCE_MANUAL);
        $this->assertRate('USD', '2026-10-10', '44.85260000', ExchangeRate::SOURCE_NBU);
    }

    public function test_api_error_fails_and_keeps_rates(): void
    {
        $this->seedUsdRate();
        Http::fake([self::NBU_URL => Http::response('Server error', 500)]);

        $this->artisan('spread:rates:update', ['--from' => '2026-10-10'])
            ->expectsOutputToContain('HTTP 500')
            ->assertFailed();

        $this->assertRate('USD', '2026-10-10', '44.00000000', ExchangeRate::SOURCE_NBU);
    }

    public function test_invalid_json_fails_and_keeps_rates(): void
    {
        $this->seedUsdRate();
        Http::fake([self::NBU_URL => Http::response('<html>maintenance</html>')]);

        $this->artisan('spread:rates:update', ['--from' => '2026-10-10'])
            ->assertFailed();

        $this->assertRate('USD', '2026-10-10', '44.00000000', ExchangeRate::SOURCE_NBU);
    }

    public function test_invalid_entry_fails(): void
    {
        Http::fake([self::NBU_URL => Http::response([['cc' => 'USD', 'rate' => -1]])]);

        $this->artisan('spread:rates:update', ['--from' => '2026-10-10'])
            ->assertFailed();

        $this->assertSame(0, ExchangeRate::query()->count());
    }

    public function test_empty_answer_is_not_an_error(): void
    {
        Http::fake([self::NBU_URL => Http::response([])]);

        $this->artisan('spread:rates:update', ['--from' => '2026-10-10'])
            ->expectsOutputToContain('NBU has not published rates yet')
            ->assertSuccessful();

        $this->assertSame(0, ExchangeRate::query()->count());
    }

    public function test_default_run_fetches_today_and_tomorrow(): void
    {
        Carbon::setTestNow('2026-10-12 23:30:00 UTC');
        Http::fake([self::NBU_URL => Http::response([])]);

        $this->artisan('spread:rates:update')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'date=20261012'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'date=20261013'));
    }

    public function test_period_fetches_every_date(): void
    {
        Http::fake([self::NBU_URL => Http::response($this->nbuRates('01.10.2026'))]);

        $this->artisan('spread:rates:update', ['--from' => '2026-10-01', '--to' => '2026-10-03'])
            ->assertSuccessful();

        Http::assertSentCount(3);
        $this->assertSame(9, ExchangeRate::query()->count());
        $this->assertRate('USD', '2026-10-03', '44.85260000', ExchangeRate::SOURCE_NBU);
    }

    public function test_invalid_dates_are_rejected(): void
    {
        Http::fake();

        $this->artisan('spread:rates:update', ['--from' => '2026-13-01'])
            ->expectsOutputToContain('Y-m-d')
            ->assertFailed();

        $this->artisan('spread:rates:update', ['--from' => '2026-10-05', '--to' => '2026-10-01'])
            ->expectsOutputToContain('--from must not be after --to')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_command_is_scheduled_daily(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $events = array_filter(
            $schedule->events(),
            fn (Event $event): bool => str_contains($event->command, 'spread:rates:update'),
        );
        $event = array_values($events)[0];

        $this->assertCount(1, $events);
        $this->assertSame('0 9 * * *', $event->expression);
        $this->assertSame('Europe/Kyiv', $event->timezone);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nbuRates(string $exchangeDate): array
    {
        return [
            ['r030' => 840, 'txt' => 'Долар США', 'rate' => 44.8526, 'cc' => 'USD', 'exchangedate' => $exchangeDate, 'special' => 'N'],
            ['r030' => 978, 'txt' => 'Євро', 'rate' => 50.1385, 'cc' => 'EUR', 'exchangedate' => $exchangeDate, 'special' => null],
            ['r030' => 392, 'txt' => 'Єна', 'rate' => 0.28341, 'cc' => 'JPY', 'exchangedate' => $exchangeDate, 'special' => null],
        ];
    }

    private function seedUsdRate(): void
    {
        ExchangeRate::create([
            'date' => '2026-10-10',
            'currency' => 'USD',
            'uah_per_unit' => '44.00',
            'source' => ExchangeRate::SOURCE_NBU,
        ]);
    }

    private function assertRate(string $currency, string $date, string $rate, string $source): void
    {
        $stored = ExchangeRate::query()
            ->where('currency', '=', $currency)
            ->where('date', '=', $date)
            ->sole();

        $this->assertSame($rate, $stored->uah_per_unit);
        $this->assertSame($source, $stored->source);
    }
}
