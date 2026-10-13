<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports;
use App\Models\Campaign;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Models\User;
use App\Reports\Dimension;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsPageTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    private Campaign $otherCampaign;

    protected function setUp(): void
    {
        parent::setUp();

        // Noon on Oct 10 in UTC is Oct 10 in Kyiv too.
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
        $this->otherCampaign = Campaign::create([
            'name' => 'Campaign Beta',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/reports')->assertRedirect('/admin/login');
    }

    public function test_page_opens_with_last_seven_days_grouped_by_campaign(): void
    {
        $this->actAsAdmin();
        $this->insertStats('2026-10-10 09:00:00', ['clicks' => 5]);

        Livewire::test(Reports::class)
            ->assertSuccessful()
            ->assertSet('tableFilters.report.from', '2026-10-04')
            ->assertSet('tableFilters.report.to', '2026-10-10')
            ->assertSet('tableFilters.report.group_1', 'campaign')
            ->assertTableColumnVisible('campaign')
            ->assertTableColumnHidden('zone')
            ->assertSee('Campaign Alpha');
    }

    public function test_two_levels_show_both_dimensions(): void
    {
        $this->actAsAdmin();
        $this->insertStats('2026-10-10 09:00:00', ['zone' => '8841', 'clicks' => 5]);
        $this->insertStats('2026-10-10 09:00:00', ['zone' => '9120', 'clicks' => 3]);

        Livewire::test(Reports::class)
            ->filterTable('report', ['from' => '2026-10-04', 'to' => '2026-10-10', 'group_1' => 'campaign', 'group_2' => 'zone'])
            ->assertTableColumnVisible('campaign')
            ->assertTableColumnVisible('zone')
            ->assertSee('8841')
            ->assertSee('9120');
    }

    public function test_second_dimension_equal_to_the_first_is_ignored(): void
    {
        $filter = Reports::reportFilter(['group_1' => 'zone', 'group_2' => 'zone']);

        $this->assertSame([Dimension::ZONE], $filter->groupBy);

        $this->actAsAdmin();

        Livewire::test(Reports::class)
            ->filterTable('report', ['from' => '2026-10-04', 'to' => '2026-10-10', 'group_1' => 'zone', 'group_2' => 'zone'])
            ->assertTableColumnVisible('zone')
            ->assertTableColumnHidden('campaign');
    }

    public function test_choosing_the_second_dimension_as_first_clears_the_second(): void
    {
        $this->actAsAdmin();

        Livewire::test(Reports::class)
            ->set('tableFilters.report.group_2', 'zone')
            ->set('tableFilters.report.group_1', 'zone')
            ->assertSet('tableFilters.report.group_2', null);
    }

    public function test_empty_zone_is_shown_as_empty(): void
    {
        $this->actAsAdmin();
        $this->insertStats('2026-10-10 09:00:00', ['zone' => '', 'clicks' => 5]);

        Livewire::test(Reports::class)
            ->filterTable('report', ['from' => '2026-10-04', 'to' => '2026-10-10', 'group_1' => 'zone'])
            ->assertSee('(empty)');
    }

    public function test_rows_sort_by_profit(): void
    {
        $this->actAsAdmin();
        $this->insertStats('2026-10-10 09:00:00', ['clicks' => 50, 'revenue' => '5']);
        $this->insertStats('2026-10-10 09:00:00', ['clicks' => 10, 'revenue' => '40'], $this->otherCampaign);

        $component = Livewire::test(Reports::class);

        $this->assertSame(['Campaign Alpha', 'Campaign Beta'], $this->campaignOrder($component));

        $component->sortTable('profit', 'desc');

        $this->assertSame(['Campaign Beta', 'Campaign Alpha'], $this->campaignOrder($component));

        $component->sortTable('profit', 'asc');

        $this->assertSame(['Campaign Alpha', 'Campaign Beta'], $this->campaignOrder($component));
    }

    /**
     * @return list<string>
     */
    private function campaignOrder(Testable $component): array
    {
        $records = $component->instance()->getTableRecords();

        return collect($records->items())->pluck('campaign')->all();
    }

    public function test_total_covers_all_rows_not_only_the_page(): void
    {
        $this->actAsAdmin();

        for ($zone = 1; $zone <= 30; $zone++) {
            $this->insertStats('2026-10-10 09:00:00', ['zone' => "z{$zone}", 'clicks' => 2]);
        }

        $component = Livewire::test(Reports::class)
            ->filterTable('report', ['from' => '2026-10-04', 'to' => '2026-10-10', 'group_1' => 'zone']);

        $records = $component->instance()->getTableRecords();

        $this->assertCount(25, $records);
        $this->assertSame(30, $records->total());
        $component->assertSeeHtml('data-total="clicks">60<');
    }

    public function test_warning_shows_only_with_missing_rates(): void
    {
        $this->actAsAdmin();
        $this->insertStats('2026-10-10 09:00:00', ['clicks' => 5]);

        Livewire::test(Reports::class)
            ->assertDontSee('Some amounts have no exchange rate');

        DB::table('costs')->insert([
            'campaign_id' => $this->campaign->id,
            'date' => '2026-10-10',
            'amount' => '10',
            'currency' => 'PLN',
        ]);

        Livewire::test(Reports::class)
            ->assertSee('Some amounts have no exchange rate')
            ->assertSee('1 costs in this period have no exchange rate');
    }

    private function actAsAdmin(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertStats(string $hour, array $attributes, ?Campaign $campaign = null): void
    {
        $campaign ??= $this->campaign;

        DB::table('report_stats')->insert(array_merge([
            'hour' => $hour,
            'campaign_id' => $campaign->id,
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
