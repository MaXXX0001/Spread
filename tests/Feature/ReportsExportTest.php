<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports;
use App\Models\Campaign;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsExportTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));

        $source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => [['param' => 'zone', 'macro' => '{zoneid}']],
            'zone_param' => 'zone',
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
            'name' => 'Campaign Alpha',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
    }

    public function test_report_csv_has_every_row(): void
    {
        for ($zone = 1; $zone <= 120; $zone++) {
            $this->insertStats(['zone' => "z{$zone}", 'clicks' => $zone]);
        }

        $component = Livewire::test(Reports::class)
            ->filterTable('report', ['from' => '2026-10-04', 'to' => '2026-10-10', 'group_1' => 'zone'])
            ->callAction('exportCsv')
            ->assertFileDownloaded('report-2026-10-04-2026-10-10.csv');

        $lines = $this->csvLines($component);

        $this->assertSame(['zone', 'clicks', 'unique_clicks', 'bot_clicks', 'conversions', 'approved', 'hold', 'rejected', 'cr', 'revenue', 'expected_revenue', 'cost', 'profit', 'roi', 'epc'], $lines[0]);
        $this->assertCount(121, $lines);
        // Sorted as the table: most clicks first.
        $this->assertSame('z120', $lines[1][0]);
    }

    public function test_numbers_use_a_dot_and_undefined_metrics_are_empty(): void
    {
        $this->insertStats(['zone' => '8841', 'clicks' => 1000, 'conversions' => 1, 'approved' => 1, 'revenue' => '1234.5']);

        $component = Livewire::test(Reports::class)
            ->filterTable('report', ['from' => '2026-10-04', 'to' => '2026-10-10', 'group_1' => 'zone'])
            ->callAction('exportCsv');

        $row = array_combine(...$this->csvLines($component));

        $this->assertSame('1000', $row['clicks']);
        $this->assertSame('0.10', $row['cr']);
        $this->assertSame('1234.50', $row['revenue']);
        $this->assertSame('0.00', $row['cost']);
        $this->assertSame('1234.50', $row['profit']);
        $this->assertSame('', $row['roi']);
        $this->assertSame('1.23', $row['epc']);
    }

    public function test_loss_zones_are_listed_worst_first(): void
    {
        $this->zoneWithProfit('8841', '10', '-12');
        $this->zoneWithProfit('9120', '4', '-3');
        $this->zoneWithProfit('7001', '10', '5');

        $component = Livewire::test(Reports::class)
            ->callAction('lossZones', ['campaign_id' => $this->campaign->id, 'min_cost' => '0'])
            ->assertHasNoFormErrors()
            ->assertFileDownloaded("loss-zones-{$this->campaign->alias}-2026-10-04-2026-10-10.csv");

        $lines = $this->csvLines($component);

        $this->assertSame(['zone', 'clicks', 'conversions', 'cost', 'revenue', 'profit', 'roi'], $lines[0]);
        $this->assertSame(['8841', '10', '0', '10.00', '-2.00', '-12.00', '-120.00'], $lines[1]);
        $this->assertSame('9120', $lines[2][0]);
        $this->assertCount(3, $lines);
    }

    public function test_threshold_drops_cheap_zones(): void
    {
        $this->zoneWithProfit('8841', '10', '-12');
        $this->zoneWithProfit('9120', '4', '-3');

        $component = Livewire::test(Reports::class)
            ->callAction('lossZones', ['campaign_id' => $this->campaign->id, 'min_cost' => '5']);

        $zones = array_column($this->csvLines($component), 0);

        $this->assertSame(['zone', '8841'], $zones);
    }

    public function test_empty_zone_is_never_listed(): void
    {
        $this->zoneWithProfit('', '10', '-10');
        $this->zoneWithProfit('8841', '10', '-1');

        $component = Livewire::test(Reports::class)
            ->callAction('lossZones', ['campaign_id' => $this->campaign->id, 'min_cost' => '0']);

        $zones = array_column($this->csvLines($component), 0);

        $this->assertSame(['zone', '8841'], $zones);
    }

    public function test_no_loss_zones_gives_a_notification_instead_of_a_file(): void
    {
        $this->zoneWithProfit('7001', '10', '5');

        Livewire::test(Reports::class)
            ->callAction('lossZones', ['campaign_id' => $this->campaign->id, 'min_cost' => '0'])
            ->assertNoFileDownloaded()
            ->assertNotified('No loss-making zones');
    }

    public function test_threshold_must_be_a_plain_non_negative_number(): void
    {
        Livewire::test(Reports::class)
            ->callAction('lossZones', ['campaign_id' => $this->campaign->id, 'min_cost' => '-1'])
            ->assertHasFormErrors(['min_cost']);

        Livewire::test(Reports::class)
            ->callAction('lossZones', ['campaign_id' => $this->campaign->id, 'min_cost' => '1e3'])
            ->assertHasFormErrors(['min_cost']);
    }

    public function test_loss_zones_campaign_defaults_to_the_single_filtered_one(): void
    {
        Livewire::test(Reports::class)
            ->filterTable('report', ['from' => '2026-10-04', 'to' => '2026-10-10', 'campaigns' => [(string) $this->campaign->id], 'group_1' => 'campaign'])
            ->mountAction('lossZones')
            ->assertSchemaStateSet(['campaign_id' => $this->campaign->id], 'mountedActionSchema0');
    }

    /**
     * A zone with 10 clicks, a zone cost and an approved revenue of cost + profit.
     */
    private function zoneWithProfit(string $zone, string $cost, string $profit): void
    {
        $revenue = bcadd($cost, $profit, 4);

        $this->insertStats(['zone' => $zone, 'clicks' => 10, 'revenue' => $revenue]);

        if ($zone !== '') {
            DB::table('costs')->insert([
                'campaign_id' => $this->campaign->id,
                'date' => '2026-10-10',
                'zone' => $zone,
                'amount' => $cost,
                'currency' => 'USD',
                'amount_base' => $cost,
                'base_currency' => 'USD',
            ]);
        }
    }

    /**
     * @return list<list<string>>
     */
    private function csvLines(Testable $component): array
    {
        $content = base64_decode(data_get($component->effects, 'download.content'));
        $lines = preg_split('/\R/', trim($content));

        return array_map(fn (string $line): array => str_getcsv($line, escape: ''), $lines);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertStats(array $attributes): void
    {
        DB::table('report_stats')->insert(array_merge([
            'hour' => '2026-10-10 09:00:00',
            'campaign_id' => $this->campaign->id,
            'zone' => '',
            'creative' => '',
            'country_code' => '',
            'device_type' => '',
            'os_name' => '',
            'clicks' => 1,
            'unique_clicks' => 0,
            'bot_clicks' => 0,
            'conversions' => 0,
            'approved' => 0,
            'hold' => 0,
            'rejected' => 0,
            'revenue' => 0,
            'expected_revenue' => 0,
            'no_rate' => 0,
        ], $attributes));
    }
}
