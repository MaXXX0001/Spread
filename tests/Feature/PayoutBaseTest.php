<?php

namespace Tests\Feature;

use App\Filament\Resources\CpaNetworks\Pages\CreateCpaNetwork;
use App\Filament\Resources\CpaNetworks\Pages\EditCpaNetwork;
use App\Filament\Resources\CpaNetworks\Pages\ViewCpaNetwork;
use App\Models\Conversion;
use App\Models\CpaNetwork;
use App\Models\ExchangeRate;
use App\Models\Postback;
use App\Models\User;
use App\Postbacks\PostbackProcessor;
use App\Postbacks\PostbackState;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PayoutBaseTest extends TestCase
{
    use RefreshDatabase;

    private const string CLICK_ID = '01J8Z3K4M5N6P7Q8R9S0T1V2W3';

    private CpaNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();

        $this->network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
        $this->addRates('2026-10-10', ['USD' => '44.8526', 'EUR' => '50.1385']);
    }

    public function test_new_network_defaults_to_usd(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');

        Livewire::test(CreateCpaNetwork::class)
            ->assertSchemaStateSet(['default_currency' => 'USD'])
            ->fillForm(['name' => 'Dr.Cash'])
            ->call('create')
            ->assertHasNoFormErrors();

        $network = CpaNetwork::query()->where('name', '=', 'Dr.Cash')->sole();

        $this->assertSame('USD', $network->default_currency);
        $this->assertSame('USD', $this->network->fresh()->default_currency);
    }

    public function test_network_currency_is_saved_upper_cased_and_shown(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');

        Livewire::test(EditCpaNetwork::class, ['record' => $this->network->getRouteKey()])
            ->fillForm(['default_currency' => 'eur'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('EUR', $this->network->fresh()->default_currency);

        Livewire::test(ViewCpaNetwork::class, ['record' => $this->network->getRouteKey()])
            ->assertSee('Default currency')
            ->assertSee('EUR');
    }

    public function test_invalid_network_currency_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');

        Livewire::test(EditCpaNetwork::class, ['record' => $this->network->getRouteKey()])
            ->fillForm(['default_currency' => 'EURO'])
            ->call('save')
            ->assertHasFormErrors(['default_currency']);

        $this->assertSame('USD', $this->network->fresh()->default_currency);
    }

    public function test_payout_in_eur_is_converted(): void
    {
        $this->process(['status' => 'approved', 'payout' => '100', 'currency' => 'EUR'], '2026-10-10 10:00:00');

        $conversion = Conversion::query()->sole();

        $this->assertSame('100.0000', $conversion->payout);
        $this->assertSame('EUR', $conversion->currency);
        $this->assertSame('111.7850', $conversion->payout_base);
        $this->assertSame('USD', $conversion->base_currency);
    }

    public function test_payout_without_currency_uses_network_currency(): void
    {
        $this->network->update(['default_currency' => 'EUR']);

        $this->process(['status' => 'approved', 'payout' => '100'], '2026-10-10 10:00:00');

        $conversion = Conversion::query()->sole();

        $this->assertNull($conversion->currency);
        $this->assertSame('111.7850', $conversion->payout_base);
    }

    public function test_rate_date_is_the_day_in_report_timezone(): void
    {
        $this->addRates('2026-10-11', ['USD' => '40', 'EUR' => '50']);

        // 22:30 UTC on Oct 10 is already Oct 11 in Kyiv.
        $this->process(['status' => 'approved', 'payout' => '100', 'currency' => 'EUR'], '2026-10-10 22:30:00');

        $this->assertSame('125.0000', Conversion::query()->sole()->payout_base);
    }

    public function test_lead_without_payout_has_no_base_payout(): void
    {
        $this->process(['status' => 'lead'], '2026-10-10 10:00:00');

        $conversion = Conversion::query()->sole();

        $this->assertNull($conversion->payout_base);
        $this->assertNull($conversion->base_currency);
    }

    public function test_payout_without_rate_has_no_base_payout(): void
    {
        $this->process(['status' => 'approved', 'payout' => '100', 'currency' => 'PLN'], '2026-10-10 10:00:00');

        $conversion = Conversion::query()->sole();

        $this->assertSame('100.0000', $conversion->payout);
        $this->assertNull($conversion->payout_base);
    }

    public function test_base_payout_follows_status_change(): void
    {
        $this->addRates('2026-10-12', ['USD' => '40', 'EUR' => '50']);
        $this->process(['status' => 'hold', 'payout' => '100', 'currency' => 'EUR'], '2026-10-10 10:00:00');

        $this->process(['status' => 'approved'], '2026-10-12 10:00:00');

        $this->assertSame('125.0000', Conversion::query()->sole()->payout_base);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function process(array $params, string $receivedAt): void
    {
        $postback = Postback::forceCreate([
            'postback_id' => (string) Str::ulid(),
            'received_at' => Carbon::parse($receivedAt, 'UTC'),
            'cpa_network_id' => $this->network->id,
            'ip' => '198.51.100.7',
            'params' => $params + ['click_id' => self::CLICK_ID],
            'state' => PostbackState::RECEIVED,
        ]);
        $processor = new PostbackProcessor;

        $processor->process($postback);
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
