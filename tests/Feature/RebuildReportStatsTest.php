<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Filament\Resources\TrafficSources\Pages\EditTrafficSource;
use App\Jobs\RebuildReportStats;
use App\Models\Campaign;
use App\Models\Conversion;
use App\Models\Cost;
use App\Models\CpaNetwork;
use App\Models\ExchangeRate;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Models\User;
use App\Money\Recalculator;
use App\Postbacks\ConversionStatus;
use App\Reports\StatsAggregator;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class RebuildReportStatsTest extends TestCase
{
    use RefreshDatabase;

    private TrafficSource $source;

    private Campaign $campaign;

    private CpaNetwork $network;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => [
                ['param' => 'zone', 'macro' => '{zoneid}'],
                ['param' => 'zid', 'macro' => '{zone_id}'],
            ],
            'zone_param' => 'zone',
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
            'traffic_source_id' => $this->source->id,
            'offer_id' => $offer->id,
        ]);
    }

    public function test_recalculated_conversion_queues_a_rebuild(): void
    {
        $this->createConversion($this->insertClick([]), '100', 'EUR');

        Queue::fake();

        app(Recalculator::class)->all();

        Queue::assertPushed(RebuildReportStats::class);
    }

    public function test_recalculating_only_costs_queues_nothing(): void
    {
        Cost::create([
            'campaign_id' => $this->campaign->id,
            'date' => '2026-10-10',
            'amount' => '100',
            'currency' => 'EUR',
        ]);

        Queue::fake();

        app(Recalculator::class)->all();

        Queue::assertNotPushed(RebuildReportStats::class);
    }

    public function test_changed_source_params_queue_a_rebuild(): void
    {
        Queue::fake();

        $this->source->update(['name' => 'PushHouse 2']);

        Queue::assertNotPushed(RebuildReportStats::class);

        $this->source->update(['creative_param' => 'zid']);

        Queue::assertPushed(RebuildReportStats::class);
    }

    public function test_base_currency_change_rebuilds_revenue_in_new_currency(): void
    {
        $this->actAsAdmin();
        $this->addRates(['USD' => '44.8526', 'EUR' => '50.1385']);
        $this->createConversion($this->insertClick([]), '100', 'USD');
        app(StatsAggregator::class)->rebuildAll();

        $this->assertSame('100.0000', DB::table('report_stats')->sole()->revenue);

        Livewire::test(Settings::class)
            ->fillForm(['base_currency' => 'EUR'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('89.4574', DB::table('report_stats')->sole()->revenue);
    }

    public function test_zone_param_change_rebuilds_zones(): void
    {
        $this->actAsAdmin();
        Repeater::fake();
        $this->insertClick(['zone' => '8841', 'zid' => '9120']);
        app(StatsAggregator::class)->rebuildAll();

        $this->assertSame('8841', DB::table('report_stats')->sole()->zone);

        Livewire::test(EditTrafficSource::class, ['record' => $this->source->getRouteKey()])
            ->fillForm(['zone_param' => 'zid'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('9120', DB::table('report_stats')->sole()->zone);
    }

    private function actAsAdmin(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
    }

    /**
     * @param  array<string, string>  $params
     */
    private function insertClick(array $params): string
    {
        $clickId = (string) Str::ulid();

        DB::table('clicks')->insert([
            'click_id' => $clickId,
            'clicked_at' => '2026-10-10 10:00:00',
            'status' => 'ok',
            'campaign_id' => $this->campaign->id,
            'offer_id' => $this->campaign->offer_id,
            'params' => json_encode($params),
            'created_at' => '2026-10-10 10:00:00',
        ]);

        return $clickId;
    }

    private function createConversion(string $clickId, string $payout, string $currency): Conversion
    {
        return Conversion::forceCreate([
            'click_id' => $clickId,
            'cpa_network_id' => $this->network->id,
            'campaign_id' => $this->campaign->id,
            'offer_id' => $this->campaign->offer_id,
            'status' => ConversionStatus::APPROVED,
            'payout' => $payout,
            'currency' => $currency,
            'last_postback_at' => '2026-10-10 10:30:00',
            'status_changed_at' => '2026-10-10 10:30:00',
        ]);
    }

    /**
     * @param  array<string, string>  $rates
     */
    private function addRates(array $rates): void
    {
        foreach ($rates as $currency => $rate) {
            ExchangeRate::create([
                'date' => '2026-10-10',
                'currency' => $currency,
                'uah_per_unit' => $rate,
                'source' => ExchangeRate::SOURCE_NBU,
            ]);
        }
    }
}
