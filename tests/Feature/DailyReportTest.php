<?php

namespace Tests\Feature;

use App\Alerts\DailyReport;
use App\Models\AlertSetting;
use App\Models\Campaign;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DailyReportTest extends TestCase
{
    use RefreshDatabase;

    private TrafficSource $source;

    private Offer $offer;

    /**
     * @var list<string>
     */
    private array $sent = [];

    private bool $telegramUp = true;

    protected function setUp(): void
    {
        parent::setUp();

        // 06:00 UTC is 09:00 in Kyiv, so "yesterday" is Oct 13.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 06:00:00', 'UTC'));
        config(['services.telegram.bot_token' => '123456:test-token']);
        AlertSetting::create(array_merge(AlertSetting::DEFAULTS, ['chat_id' => 42]));

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

        Http::fake(function (Request $request) {
            if (! $this->telegramUp) {
                return Http::response(['ok' => false, 'description' => 'Bad Gateway'], 502);
            }

            $this->sent[] = $request['text'];

            return Http::response(['ok' => true]);
        });
    }

    public function test_totals_of_yesterday(): void
    {
        $campaign = $this->createCampaign('Alpha');
        $this->insertStats($campaign, '2026-10-13 09:00:00', 3000, 15, '120');
        $this->insertCost($campaign, '2026-10-13', '80');

        $text = $this->reportOfYesterday();

        $this->assertStringContainsString('📊 <b>Spread — Tue, 13 Oct 2026</b>', $text);
        $this->assertStringContainsString('Profit <b>40.00 USD</b> · ROI 50.00%', $text);
        $this->assertStringContainsString('Revenue 120.00 USD · Cost 80.00 USD', $text);
        $this->assertStringContainsString('Clicks 3000 · Conversions 15', $text);
    }

    public function test_top_and_worst_three_campaigns(): void
    {
        $profits = ['A' => '40', 'B' => '30', 'C' => '20', 'D' => '10', 'E' => '-5', 'F' => '-25', 'G' => '-15', 'H' => '-1'];

        foreach ($profits as $name => $profit) {
            $campaign = $this->createCampaign($name);
            $revenue = bcadd($profit, '30', 4);
            $this->insertStats($campaign, '2026-10-13 09:00:00', 10, 1, $revenue);
            $this->insertCost($campaign, '2026-10-13', '30');
        }

        $text = $this->reportOfYesterday();

        $this->assertStringContainsString("<b>Top</b>\n1. A: 40.00 USD (ROI 133.33%)\n2. B: 30.00 USD (ROI 100.00%)\n3. C: 20.00 USD (ROI 66.67%)\n", $text);
        $this->assertStringContainsString("<b>Worst</b>\n1. F: -25.00 USD (ROI -83.33%)\n2. G: -15.00 USD (ROI -50.00%)\n3. E: -5.00 USD (ROI -16.67%)", $text);
        $this->assertStringNotContainsString('D:', $text);
        $this->assertStringNotContainsString('H:', $text);
    }

    public function test_campaign_with_clicks_and_no_costs_is_warned_about(): void
    {
        $paid = $this->createCampaign('Paid');
        $unpaid = $this->createCampaign('PH / Nutra <UA>');
        $this->insertStats($paid, '2026-10-13 09:00:00', 100, 0, '0');
        $this->insertCost($paid, '2026-10-13', '10');
        $this->insertStats($unpaid, '2026-10-13 09:00:00', 500, 0, '0');

        $text = $this->reportOfYesterday();

        $this->assertStringContainsString('⚠️ Clicks but no costs: PH / Nutra &lt;UA&gt;.', $text);
    }

    public function test_costs_without_rate_are_warned_about(): void
    {
        $campaign = $this->createCampaign('Alpha');
        DB::table('costs')->insert([
            'campaign_id' => $campaign->id,
            'date' => '2026-10-13',
            'amount' => '10',
            'currency' => 'PLN',
        ]);

        $text = $this->reportOfYesterday();

        $this->assertStringContainsString('⚠️ 1 costs have no exchange rate and are left out.', $text);
    }

    public function test_empty_day(): void
    {
        $text = $this->reportOfYesterday();

        $this->assertStringContainsString('Profit <b>0.00 USD</b> · ROI —', $text);
        $this->assertStringContainsString('Clicks 0 · Conversions 0', $text);
        $this->assertStringNotContainsString('<b>Top</b>', $text);
        $this->assertStringNotContainsString('⚠️', $text);
    }

    public function test_report_is_sent_at_its_time_once(): void
    {
        $this->artisan('spread:alerts:daily')->assertSuccessful();

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('13 Oct 2026', $this->sent[0]);
        $this->assertSame('2026-10-14', AlertSetting::current()->daily_sent_on->format('Y-m-d'));

        $this->travel(1)->minutes();
        $this->artisan('spread:alerts:daily')->assertSuccessful();

        $this->assertCount(1, $this->sent);
    }

    public function test_nothing_is_sent_before_the_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 05:59:00', 'UTC'));

        $this->artisan('spread:alerts:daily')->assertSuccessful();

        $this->assertSame([], $this->sent);
    }

    public function test_late_run_on_the_same_day_still_sends(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 15:00:00', 'UTC'));

        $this->artisan('spread:alerts:daily')->assertSuccessful();

        $this->assertCount(1, $this->sent);
    }

    public function test_disabled_report_is_not_sent(): void
    {
        AlertSetting::current()->update(['daily_enabled' => false]);

        $this->artisan('spread:alerts:daily')->assertSuccessful();

        $this->assertSame([], $this->sent);
    }

    public function test_manual_run_sends_any_time_and_keeps_the_schedule(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 03:00:00', 'UTC'));
        AlertSetting::current()->update(['daily_enabled' => false]);

        $this->artisan('spread:alerts:daily', ['--now' => true])
            ->expectsOutputToContain('Daily report for 2026-10-13 sent.')
            ->assertSuccessful();

        $this->assertCount(1, $this->sent);
        $this->assertNull(AlertSetting::current()->daily_sent_on);
    }

    public function test_failed_delivery_is_retried_on_the_next_run(): void
    {
        $this->telegramUp = false;

        $this->artisan('spread:alerts:daily')->assertFailed();

        $this->assertNull(AlertSetting::current()->daily_sent_on);

        $this->telegramUp = true;
        $this->travel(1)->minutes();
        $this->artisan('spread:alerts:daily')->assertSuccessful();

        $this->assertCount(1, $this->sent);
    }

    public function test_command_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'spread:alerts:daily'));

        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function reportOfYesterday(): string
    {
        $day = CarbonImmutable::parse('2026-10-13');

        return app(DailyReport::class)->text($day);
    }

    private function createCampaign(string $name): Campaign
    {
        return Campaign::create([
            'name' => $name,
            'traffic_source_id' => $this->source->id,
            'offer_id' => $this->offer->id,
        ]);
    }

    private function insertStats(Campaign $campaign, string $hour, int $clicks, int $conversions, string $revenue): void
    {
        DB::table('report_stats')->insert([
            'hour' => $hour,
            'campaign_id' => $campaign->id,
            'zone' => '',
            'creative' => '',
            'country_code' => '',
            'device_type' => '',
            'os_name' => '',
            'clicks' => $clicks,
            'unique_clicks' => $clicks,
            'bot_clicks' => 0,
            'conversions' => $conversions,
            'approved' => $conversions,
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
