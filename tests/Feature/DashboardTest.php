<?php

namespace Tests\Feature;

use App\Filament\Widgets\ProfitOverview;
use App\Filament\Widgets\TopCampaigns;
use App\Filament\Widgets\WorstCampaigns;
use App\Models\Campaign;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private TrafficSource $source;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));

        $this->source = TrafficSource::create([
            'name' => 'PushHouse',
            'macros' => [['param' => 'zone', 'macro' => '{zoneid}']],
        ]);
        $network = CpaNetwork::create([
            'name' => 'AdCombo',
        ]);
        $this->offer = Offer::create([
            'name' => 'Nutra UA',
            'cpa_network_id' => $network->id,
            'url_template' => 'https://offer.example/lp?sub1={click_id}',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_dashboard_has_report_widgets_and_no_filament_info(): void
    {
        $this->actAsAdmin();

        $widgets = Filament::getWidgets();

        $this->assertNotContains(FilamentInfoWidget::class, $widgets);
        $this->assertContains(ProfitOverview::class, $widgets);
        $this->assertContains(TopCampaigns::class, $widgets);
        $this->assertContains(WorstCampaigns::class, $widgets);

        $this->get('/admin')
            ->assertSuccessful()
            ->assertDontSee('filamentphp.com');
    }

    public function test_today_shows_profit_revenue_and_cost(): void
    {
        $this->actAsAdmin();
        $campaign = $this->createCampaign('Alpha');
        $this->insertStats($campaign, '2026-10-10 09:00:00', '50');
        $this->insertCost($campaign, '2026-10-10', '30');

        Livewire::test(ProfitOverview::class)
            ->assertSee('Today')
            ->assertSee('20.00 USD')
            ->assertSee('Revenue 50.00 · Cost 30.00');
    }

    public function test_last_seven_days_start_six_days_ago(): void
    {
        $this->actAsAdmin();
        $campaign = $this->createCampaign('Alpha');
        $this->insertCost($campaign, '2026-10-04', '10');
        $this->insertCost($campaign, '2026-10-03', '10');

        Livewire::test(ProfitOverview::class)
            ->assertSee('Last 7 days')
            ->assertSee('-10.00 USD')
            ->assertDontSee('-20.00 USD');
    }

    public function test_campaigns_split_into_top_and_worst(): void
    {
        $this->actAsAdmin();
        $profits = ['A' => '40', 'B' => '10', 'C' => '0', 'D' => '-5', 'E' => '-25'];

        foreach ($profits as $name => $profit) {
            $campaign = $this->createCampaign($name);
            $this->insertStats($campaign, '2026-10-09 09:00:00', bcadd($profit, '30', 4));
            $this->insertCost($campaign, '2026-10-09', '30');
        }

        $this->assertSame(['A', 'B'], $this->campaignsOf(TopCampaigns::class));
        $this->assertSame(['E', 'D'], $this->campaignsOf(WorstCampaigns::class));
    }

    public function test_top_shows_at_most_five(): void
    {
        $this->actAsAdmin();

        for ($i = 1; $i <= 7; $i++) {
            $campaign = $this->createCampaign("P{$i}");
            $this->insertStats($campaign, '2026-10-09 09:00:00', (string) $i);
        }

        $this->assertSame(['P7', 'P6', 'P5', 'P4', 'P3'], $this->campaignsOf(TopCampaigns::class));
    }

    /**
     * @param  class-string  $widget
     * @return list<string>
     */
    private function campaignsOf(string $widget): array
    {
        $records = Livewire::test($widget)->instance()->getTableRecords();

        return collect($records)->pluck('campaign')->values()->all();
    }

    private function actAsAdmin(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
    }

    private function createCampaign(string $name): Campaign
    {
        return Campaign::create([
            'name' => $name,
            'traffic_source_id' => $this->source->id,
            'offer_id' => $this->offer->id,
        ]);
    }

    private function insertStats(Campaign $campaign, string $hour, string $revenue): void
    {
        DB::table('report_stats')->insert([
            'hour' => $hour,
            'campaign_id' => $campaign->id,
            'zone' => '',
            'creative' => '',
            'country_code' => '',
            'device_type' => '',
            'os_name' => '',
            'clicks' => 10,
            'unique_clicks' => 10,
            'bot_clicks' => 0,
            'conversions' => 1,
            'approved' => 1,
            'hold' => 0,
            'rejected' => 0,
            'revenue' => $revenue,
            'expected_revenue' => 0,
            'no_rate' => 0,
        ]);
    }

    private function insertCost(Campaign $campaign, string $date, string $amount): void
    {
        DB::table('costs')->insert([
            'campaign_id' => $campaign->id,
            'date' => $date,
            'amount' => $amount,
            'currency' => 'USD',
            'amount_base' => $amount,
            'base_currency' => 'USD',
        ]);
    }
}
