<?php

namespace Tests\Feature;

use App\Filament\Resources\Costs\Pages\CreateCost;
use App\Filament\Resources\Costs\Pages\EditCost;
use App\Filament\Resources\Costs\Pages\ListCosts;
use App\Models\Campaign;
use App\Models\Cost;
use App\Models\CpaNetwork;
use App\Models\ExchangeRate;
use App\Models\Offer;
use App\Models\Setting;
use App\Models\TrafficSource;
use App\Models\User;
use App\Money\CostRules;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CostResourceTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    private Campaign $otherCampaign;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->actingAs($user);

        Filament::setCurrentPanel('admin');

        $source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => [['param' => 'zone', 'macro' => '{zoneid}']],
        ]);
        $network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
        $offer = Offer::create([
            'name' => 'Nutra UA',
            'cpa_network_id' => $network->id,
            'url_template' => 'https://offer.example/lp?sub1={click_id}',
        ]);
        $this->campaign = Campaign::create([
            'name' => 'PH / Nutra UA',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);
        $this->otherCampaign = Campaign::create([
            'name' => 'PH / Nutra PL',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);

        foreach (['USD' => '44.8526', 'EUR' => '50.1385'] as $currency => $rate) {
            ExchangeRate::create([
                'date' => '2026-10-10',
                'currency' => $currency,
                'uah_per_unit' => $rate,
                'source' => ExchangeRate::SOURCE_NBU,
            ]);
        }
    }

    public function test_cost_is_created_and_listed_with_base_amount(): void
    {
        Livewire::test(CreateCost::class)
            ->fillForm([
                'campaign_id' => $this->campaign->id,
                'date' => '2026-10-10',
                'amount' => '25.50',
                'currency' => 'eur',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cost = Cost::query()->sole();

        $this->assertNull($cost->zone);
        $this->assertSame('EUR', $cost->currency);
        $this->assertSame('28.5052', $cost->amount_base);

        Livewire::test(ListCosts::class)
            ->assertCanSeeTableRecords([$cost])
            ->assertSee('PH / Nutra UA')
            ->assertTableColumnStateSet('amount', '25.5 EUR', record: $cost)
            ->assertTableColumnStateSet('amount_base', '28.5052 USD', record: $cost);
    }

    public function test_currency_defaults_to_base_currency(): void
    {
        Setting::create(['base_currency' => 'EUR', 'report_timezone' => 'Europe/Kyiv']);

        Livewire::test(CreateCost::class)
            ->assertSchemaStateSet(['currency' => 'EUR']);
    }

    public function test_zone_costs_of_one_day_are_created(): void
    {
        foreach (['8841', '9120'] as $zone) {
            Livewire::test(CreateCost::class)
                ->fillForm($this->validData(['zone' => $zone]))
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertSame(2, Cost::query()->count());
    }

    /**
     * @return array<string, array{?string, ?string, string}>
     */
    public static function conflictProvider(): array
    {
        return [
            'duplicate zone' => ['8841', '8841', CostRules::DUPLICATE],
            'duplicate whole campaign' => [null, null, CostRules::DUPLICATE],
            'whole campaign next to zones' => ['8841', null, CostRules::ZONES_EXIST],
            'zone next to whole campaign' => [null, '8841', CostRules::CAMPAIGN_COST_EXISTS],
        ];
    }

    #[DataProvider('conflictProvider')]
    public function test_conflicting_cost_is_rejected(?string $existingZone, ?string $newZone, string $message): void
    {
        $this->createCost('2026-10-10', $existingZone);

        Livewire::test(CreateCost::class)
            ->fillForm($this->validData(['zone' => $newZone]))
            ->call('create')
            ->assertHasFormErrors(['date'])
            ->assertSee($message);

        $this->assertSame(1, Cost::query()->count());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidDataProvider(): array
    {
        return [
            'five decimals' => [['amount' => '1.12345'], 'amount'],
            'negative amount' => [['amount' => '-5'], 'amount'],
            'comma decimal' => [['amount' => '12,5'], 'amount'],
            'currency too long' => [['currency' => 'EURO'], 'currency'],
            'zone too long' => [['zone' => str_repeat('z', 256)], 'zone'],
        ];
    }

    /**
     * @param  array<string, string>  $data
     */
    #[DataProvider('invalidDataProvider')]
    public function test_invalid_cost_is_rejected(array $data, string $field): void
    {
        Livewire::test(CreateCost::class)
            ->fillForm($this->validData($data))
            ->call('create')
            ->assertHasFormErrors([$field]);

        $this->assertSame(0, Cost::query()->count());
    }

    public function test_cost_without_rate_shows_no_rate(): void
    {
        $cost = $this->createCost('2026-10-10', null, currency: 'PLN');

        Livewire::test(ListCosts::class)
            ->assertTableColumnStateSet('amount_base', 'no rate', record: $cost);
    }

    public function test_newest_date_is_listed_first_and_filters_work(): void
    {
        $older = $this->createCost('2026-10-01', null);
        $newer = $this->createCost('2026-10-08', null);
        $other = $this->createCost('2026-10-05', null, campaign: $this->otherCampaign);

        Livewire::test(ListCosts::class)
            ->assertCanSeeTableRecords([$newer, $other, $older], inOrder: true);

        Livewire::test(ListCosts::class)
            ->filterTable('period', ['from' => '2026-10-01', 'until' => '2026-10-07'])
            ->assertCanSeeTableRecords([$older, $other])
            ->assertCanNotSeeTableRecords([$newer]);

        Livewire::test(ListCosts::class)
            ->filterTable('campaign', $this->otherCampaign->id)
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$older, $newer]);
    }

    public function test_cost_is_edited_and_recalculated(): void
    {
        $cost = $this->createCost('2026-10-10', null, amount: '10', currency: 'USD');

        Livewire::test(EditCost::class, ['record' => $cost->getRouteKey()])
            ->fillForm([
                'amount' => '100',
                'currency' => 'EUR',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $cost->refresh();

        $this->assertSame('100.0000', $cost->amount);
        $this->assertSame('111.7850', $cost->amount_base);
    }

    public function test_cost_is_saved_unchanged(): void
    {
        $cost = $this->createCost('2026-10-10', '8841');

        Livewire::test(EditCost::class, ['record' => $cost->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_cost_is_deleted(): void
    {
        $cost = $this->createCost('2026-10-10', null);

        Livewire::test(EditCost::class, ['record' => $cost->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($cost);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        Auth::logout();

        $response = $this->get('/admin/costs');

        $response->assertRedirect('/admin/login');
    }

    /**
     * @param  array<string, string|null>  $overrides
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return $overrides + [
            'campaign_id' => $this->campaign->id,
            'date' => '2026-10-10',
            'zone' => null,
            'amount' => '10',
            'currency' => 'USD',
        ];
    }

    private function createCost(string $date, ?string $zone, string $amount = '10', string $currency = 'USD', ?Campaign $campaign = null): Cost
    {
        $campaign ??= $this->campaign;

        return Cost::create([
            'campaign_id' => $campaign->id,
            'date' => $date,
            'zone' => $zone,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }
}
