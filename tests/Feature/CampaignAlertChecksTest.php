<?php

namespace Tests\Feature;

use App\Alerts\Checks\HighCrCheck;
use App\Alerts\Checks\RoiCheck;
use App\Alerts\Checks\SilenceCheck;
use App\Models\AlertSetting;
use App\Models\Campaign;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignAlertChecksTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    private CpaNetwork $network;

    private AlertSetting $settings;

    protected function setUp(): void
    {
        parent::setUp();

        // 12:00 UTC is 15:00 on the same day in Kyiv.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
        config(['app.url' => 'http://spread.local']);

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
            'name' => 'PH / Nutra <UA>',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);
        $this->settings = new AlertSetting(AlertSetting::DEFAULTS);
    }

    public function test_roi_alert_for_a_draining_campaign(): void
    {
        $this->insertStats(['clicks' => 100, 'revenue' => '10']);
        $this->insertCost('2026-10-14', '25');

        $problems = app(RoiCheck::class)->problems($this->settings);

        $this->assertSame(["campaign:{$this->campaign->id}"], array_keys($problems));
        $message = $problems["campaign:{$this->campaign->id}"];
        $this->assertStringContainsString('<b>PH / Nutra &lt;UA&gt;</b>', $message);
        $this->assertStringContainsString('ROI -60.00% today (limit -30.00%)', $message);
        $this->assertStringContainsString('Cost 25.00 USD · Revenue 10.00 USD', $message);
        $this->assertStringContainsString('<a href="http://spread.local/admin/reports">Reports</a>', $message);
    }

    public function test_no_roi_alert_below_minimum_cost(): void
    {
        $this->insertStats(['clicks' => 100]);
        $this->insertCost('2026-10-14', '15');

        $this->assertSame([], app(RoiCheck::class)->problems($this->settings));
    }

    public function test_no_roi_alert_for_yesterday(): void
    {
        $this->insertStats(['hour' => '2026-10-13 09:00:00', 'clicks' => 100]);
        $this->insertCost('2026-10-13', '50');

        $this->assertSame([], app(RoiCheck::class)->problems($this->settings));
    }

    public function test_silence_alert_for_clicks_without_conversions(): void
    {
        $this->insertClicks(300, '2026-10-14 08:00:00');

        $problems = app(SilenceCheck::class)->problems($this->settings);

        $this->assertSame(["campaign:{$this->campaign->id}"], array_keys($problems));
        $this->assertStringContainsString('300 clicks and no conversions in the last 6h', $problems["campaign:{$this->campaign->id}"]);
    }

    public function test_no_silence_alert_with_few_clicks(): void
    {
        $this->insertClicks(40, '2026-10-14 08:00:00');

        $this->assertSame([], app(SilenceCheck::class)->problems($this->settings));
    }

    public function test_clicks_before_the_window_do_not_count(): void
    {
        $this->insertClicks(300, '2026-10-14 05:59:00');

        $this->assertSame([], app(SilenceCheck::class)->problems($this->settings));
    }

    public function test_no_silence_alert_for_a_disabled_campaign(): void
    {
        $this->campaign->update(['active' => false]);
        $this->insertClicks(300, '2026-10-14 08:00:00');

        $this->assertSame([], app(SilenceCheck::class)->problems($this->settings));
    }

    public function test_conversion_created_in_the_window_for_an_old_click_ends_silence(): void
    {
        $this->insertClicks(300, '2026-10-14 08:00:00');
        $oldClickId = $this->insertClicks(1, '2026-10-10 08:00:00');
        $this->insertConversion($oldClickId, '2026-10-14 11:00:00');

        $this->assertSame([], app(SilenceCheck::class)->problems($this->settings));
    }

    public function test_conversion_before_the_window_does_not_end_silence(): void
    {
        $clickId = $this->insertClicks(300, '2026-10-14 08:00:00');
        $this->insertConversion($clickId, '2026-10-14 05:00:00');

        $this->assertCount(1, app(SilenceCheck::class)->problems($this->settings));
    }

    public function test_high_cr_alert(): void
    {
        $this->insertStats(['clicks' => 60, 'conversions' => 18]);

        $problems = app(HighCrCheck::class)->problems($this->settings);

        $this->assertSame(["campaign:{$this->campaign->id}"], array_keys($problems));
        $message = $problems["campaign:{$this->campaign->id}"];
        $this->assertStringContainsString('CR 30.00% today (limit 20.00%)', $message);
        $this->assertStringContainsString('18 conversions from 60 clicks', $message);
    }

    public function test_no_high_cr_alert_with_few_clicks(): void
    {
        $this->insertStats(['clicks' => 40, 'conversions' => 20]);

        $this->assertSame([], app(HighCrCheck::class)->problems($this->settings));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertStats(array $attributes): void
    {
        DB::table('report_stats')->insert(array_merge([
            'hour' => '2026-10-14 09:00:00',
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

    private function insertCost(string $date, string $amount): void
    {
        DB::table('costs')->insert([
            'campaign_id' => $this->campaign->id,
            'date' => $date,
            'amount' => $amount,
            'currency' => 'USD',
            'amount_base' => $amount,
            'base_currency' => 'USD',
        ]);
    }

    /**
     * @return string The click ID of the last inserted click.
     */
    private function insertClicks(int $count, string $clickedAt): string
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'click_id' => (string) Str::ulid(),
                'clicked_at' => $clickedAt,
                'status' => 'ok',
                'campaign_id' => $this->campaign->id,
                'offer_id' => $this->campaign->offer_id,
                'params' => '{}',
                'created_at' => $clickedAt,
            ];
        }

        DB::table('clicks')->insert($rows);

        return end($rows)['click_id'];
    }

    private function insertConversion(string $clickId, string $createdAt): void
    {
        DB::table('conversions')->insert([
            'click_id' => $clickId,
            'cpa_network_id' => $this->network->id,
            'campaign_id' => $this->campaign->id,
            'offer_id' => $this->campaign->offer_id,
            'status' => 'lead',
            'last_postback_at' => $createdAt,
            'status_changed_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
