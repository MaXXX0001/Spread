<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Filament\Resources\ExchangeRates\Pages\CreateExchangeRate;
use App\Filament\Resources\ExchangeRates\Pages\EditExchangeRate;
use App\Models\Campaign;
use App\Models\Conversion;
use App\Models\Cost;
use App\Models\CpaNetwork;
use App\Models\ExchangeRate;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Models\User;
use App\Postbacks\ConversionStatus;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class RecalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    private CpaNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();

        $source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => [['param' => 'zone', 'macro' => '{zoneid}']],
        ]);
        $this->network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
        $offer = Offer::create([
            'name' => 'Nutra UA',
            'cpa_network_id' => $this->network->id,
            'url_template' => 'https://offer.example/lp?sub1={click_id}',
        ]);
        $this->campaign = Campaign::create([
            'name' => 'PH / Nutra UA',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);
    }

    public function test_rates_update_fills_missing_amounts(): void
    {
        $cost = $this->createCost('100', 'EUR', '2026-10-10');
        $conversion = $this->createConversion('100', 'EUR', '2026-10-10 10:00:00');
        Http::fake(['bank.gov.ua/*' => Http::response([
            ['cc' => 'USD', 'rate' => 44.8526],
            ['cc' => 'EUR', 'rate' => 50.1385],
        ])]);

        $this->artisan('spread:rates:update', ['--from' => '2026-10-10'])
            ->expectsOutputToContain('Recalculated 1 costs and 1 conversions without a base amount.')
            ->assertSuccessful();

        $this->assertSame('111.7850', $cost->fresh()->amount_base);
        $this->assertSame('111.7850', $conversion->fresh()->payout_base);
    }

    public function test_command_recalculates_only_missing_and_stale_by_default(): void
    {
        $this->addRates('2026-10-10', ['USD' => '44.8526', 'EUR' => '50.1385']);
        $current = $this->createCost('100', 'EUR', '2026-10-10');
        $missing = $this->createCost('100', 'EUR', '2026-10-11');
        $stale = $this->createCost('100', 'EUR', '2026-10-12');
        $this->createConversion(null, null, '2026-10-10 10:00:00');
        DB::table('costs')->where('id', '=', $missing->id)->update(['amount_base' => null, 'base_currency' => null]);
        DB::table('costs')->where('id', '=', $stale->id)->update(['amount_base' => '1', 'base_currency' => 'EUR']);
        DB::table('costs')->where('id', '=', $current->id)->update(['amount_base' => '999']);

        $this->artisan('spread:money:recalculate')
            ->expectsOutput('Recalculated 2 costs and 0 conversions.')
            ->assertSuccessful();

        $this->assertSame('999.0000', $current->fresh()->amount_base);
        $this->assertSame('111.7850', $missing->fresh()->amount_base);
        $this->assertSame('111.7850', $stale->fresh()->amount_base);
        $this->assertSame('USD', $stale->fresh()->base_currency);

        $this->artisan('spread:money:recalculate', ['--all' => true])
            ->expectsOutput('Recalculated 3 costs and 0 conversions.')
            ->assertSuccessful();

        $this->assertSame('111.7850', $current->fresh()->amount_base);
    }

    public function test_manual_rate_change_recalculates_amounts_that_used_it(): void
    {
        $this->actAsAdmin();
        $this->addRates('2026-10-10', ['USD' => '44.8526']);
        $euroRate = ExchangeRate::create(['date' => '2026-10-10', 'currency' => 'EUR', 'uah_per_unit' => '50.1385', 'source' => ExchangeRate::SOURCE_NBU]);
        $sameDay = $this->createCost('100', 'EUR', '2026-10-10', zone: '9120');
        $laterDay = $this->createCost('100', 'EUR', '2026-10-12');
        $dayBefore = $this->createCost('100', 'EUR', '2026-10-09');
        $dollars = $this->createCost('100', 'USD', '2026-10-10', zone: '8841');
        $conversion = $this->createConversion('100', 'EUR', '2026-10-10 10:00:00');
        DB::table('costs')->where('id', '=', $dayBefore->id)->update(['amount_base' => '1', 'base_currency' => 'USD']);

        Livewire::test(EditExchangeRate::class, ['record' => $euroRate->getRouteKey()])
            ->fillForm(['uah_per_unit' => '44.8526'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('100.0000', $sameDay->fresh()->amount_base);
        $this->assertSame('100.0000', $laterDay->fresh()->amount_base);
        $this->assertSame('100.0000', $conversion->fresh()->payout_base);
        $this->assertSame('1.0000', $dayBefore->fresh()->amount_base);
        $this->assertSame('100.0000', $dollars->fresh()->amount_base);
    }

    public function test_new_manual_rate_fills_amounts_without_rate(): void
    {
        $this->actAsAdmin();
        $this->addRates('2026-10-20', ['EUR' => '50']);
        $cost = $this->createCost('10', 'EUR', '2026-10-20');

        $this->assertNull($cost->amount_base);

        Livewire::test(CreateExchangeRate::class)
            ->fillForm(['date' => '2026-10-20', 'currency' => 'USD', 'uah_per_unit' => '40'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('12.5000', $cost->fresh()->amount_base);
    }

    public function test_base_currency_change_recalculates_everything(): void
    {
        $this->actAsAdmin();
        $this->addRates('2026-10-10', ['USD' => '44.8526', 'EUR' => '50.1385']);
        $cost = $this->createCost('1000', 'UAH', '2026-10-10');
        $conversion = $this->createConversion('100', 'USD', '2026-10-10 10:00:00');

        Livewire::test(Settings::class)
            ->fillForm(['base_currency' => 'EUR'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved.');

        $cost->refresh();
        $conversion->refresh();

        $this->assertSame('19.9448', $cost->amount_base);
        $this->assertSame('EUR', $cost->base_currency);
        $this->assertSame('89.4574', $conversion->payout_base);
        $this->assertSame('EUR', $conversion->base_currency);
    }

    public function test_saving_settings_without_currency_change_recalculates_nothing(): void
    {
        $this->actAsAdmin();
        $cost = $this->createCost('100', 'EUR', '2026-10-10');
        DB::table('costs')->where('id', '=', $cost->id)->update(['amount_base' => '5', 'base_currency' => 'USD']);

        Livewire::test(Settings::class)
            ->fillForm(['report_timezone' => 'Europe/Warsaw'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('5.0000', $cost->fresh()->amount_base);
    }

    private function actAsAdmin(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
    }

    private function createCost(string $amount, string $currency, string $date, ?string $zone = null): Cost
    {
        return Cost::create([
            'campaign_id' => $this->campaign->id,
            'date' => $date,
            'zone' => $zone,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    private function createConversion(?string $payout, ?string $currency, string $statusChangedAt): Conversion
    {
        return Conversion::forceCreate([
            'click_id' => (string) Str::ulid(),
            'cpa_network_id' => $this->network->id,
            'status' => ConversionStatus::APPROVED,
            'payout' => $payout,
            'currency' => $currency,
            'last_postback_at' => $statusChangedAt,
            'status_changed_at' => $statusChangedAt,
        ]);
    }

    /**
     * @param  array<string, string>  $rates
     */
    private function addRates(string $date, array $rates): void
    {
        foreach ($rates as $currency => $rate) {
            ExchangeRate::create([
                'date' => $date,
                'currency' => $currency,
                'uah_per_unit' => $rate,
                'source' => ExchangeRate::SOURCE_NBU,
            ]);
        }
    }
}
