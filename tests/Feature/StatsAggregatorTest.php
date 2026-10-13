<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Reports\StatsAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StatsAggregatorTest extends TestCase
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
                ['param' => 'zid', 'macro' => '{zoneid}'],
                ['param' => 'creative', 'macro' => '{bannerid}'],
            ],
            'zone_param' => 'zid',
            'creative_param' => 'creative',
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

    public function test_dimensions_come_from_the_source_params_and_the_click(): void
    {
        $this->insertClick('2026-10-10 21:15:30.123', [
            'params' => ['zid' => '8841', 'creative' => 'b7'],
            'country_code' => 'UA',
            'device_type' => 'smartphone',
            'os_name' => 'Android',
        ]);

        $this->aggregateDay('2026-10-10');

        $row = DB::table('report_stats')->sole();

        $this->assertSame('2026-10-10 21:00:00', $row->hour);
        $this->assertSame($this->campaign->id, $row->campaign_id);
        $this->assertSame('8841', $row->zone);
        $this->assertSame('b7', $row->creative);
        $this->assertSame('UA', $row->country_code);
        $this->assertSame('smartphone', $row->device_type);
        $this->assertSame('Android', $row->os_name);
        $this->assertSame(1, $row->clicks);
    }

    public function test_missing_values_and_unset_params_are_empty_strings(): void
    {
        $this->source->update(['creative_param' => null]);

        $this->insertClick('2026-10-10 10:00:00', [
            'params' => ['creative' => 'b7'],
        ]);

        $this->aggregateDay('2026-10-10');

        $row = DB::table('report_stats')->sole();

        $this->assertSame('', $row->zone);
        $this->assertSame('', $row->creative);
        $this->assertSame('', $row->country_code);
        $this->assertSame('', $row->device_type);
        $this->assertSame('', $row->os_name);
    }

    public function test_unsubstituted_macro_is_kept_as_a_value(): void
    {
        $this->insertClick('2026-10-10 10:00:00', ['params' => ['zid' => '{zoneid}']]);
        $this->insertClick('2026-10-10 10:05:00', ['params' => ['zid' => '8841']]);

        $this->aggregateDay('2026-10-10');

        $zones = DB::table('report_stats')->orderBy('zone')->pluck('zone')->all();

        $this->assertSame(['8841', '{zoneid}'], $zones);
    }

    public function test_click_counters(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->insertClick('2026-10-10 10:00:00', [
                'is_duplicate' => $i < 3,
                'is_bot' => $i >= 8,
            ]);
        }

        $this->aggregateDay('2026-10-10');

        $row = DB::table('report_stats')->sole();

        $this->assertSame(10, $row->clicks);
        $this->assertSame(7, $row->unique_clicks);
        $this->assertSame(2, $row->bot_clicks);
    }

    public function test_click_of_a_disabled_campaign_is_counted(): void
    {
        $this->insertClick('2026-10-10 10:00:00', ['status' => 'campaign_disabled']);

        $this->aggregateDay('2026-10-10');

        $this->assertSame(1, DB::table('report_stats')->sole()->clicks);
    }

    public function test_conversion_counters_and_revenue(): void
    {
        $conversions = [
            ['lead', null],
            ['hold', '5.0000'],
            ['approved', '10.0000'],
            ['approved', '10.0000'],
            ['rejected', '10.0000'],
        ];

        foreach ($conversions as [$status, $payout]) {
            $clickId = $this->insertClick('2026-10-10 10:00:00');
            $this->insertConversion($clickId, $status, $payout, $payout);
        }

        $this->insertClick('2026-10-10 10:00:00');

        $this->aggregateDay('2026-10-10');

        $row = DB::table('report_stats')->sole();

        $this->assertSame(6, $row->clicks);
        $this->assertSame(4, $row->conversions);
        $this->assertSame(2, $row->approved);
        $this->assertSame(1, $row->hold);
        $this->assertSame(1, $row->rejected);
        $this->assertSame('20.0000', $row->revenue);
        $this->assertSame('5.0000', $row->expected_revenue);
        $this->assertSame(0, $row->no_rate);
    }

    public function test_payout_without_base_amount_is_counted_as_no_rate(): void
    {
        $clickId = $this->insertClick('2026-10-10 10:00:00');
        $this->insertConversion($clickId, 'approved', '10.0000', null);

        $this->aggregateDay('2026-10-10');

        $row = DB::table('report_stats')->sole();

        $this->assertSame('0.0000', $row->revenue);
        $this->assertSame(1, $row->no_rate);
    }

    public function test_conversion_is_counted_in_the_hour_of_its_click(): void
    {
        $clickId = $this->insertClick('2026-10-10 10:20:00');
        $this->insertConversion($clickId, 'approved', '12.0000', '12.0000', '2026-10-12 08:00:00');

        $this->aggregateDay('2026-10-10');
        $this->aggregateDay('2026-10-12');

        $row = DB::table('report_stats')->sole();

        $this->assertSame('2026-10-10 10:00:00', $row->hour);
        $this->assertSame(1, $row->approved);
        $this->assertSame('12.0000', $row->revenue);
    }

    public function test_orphan_conversion_is_not_counted(): void
    {
        $this->insertClick('2026-10-10 10:00:00');
        $this->insertConversion((string) Str::ulid(), 'approved', '10.0000', '10.0000');

        $this->aggregateDay('2026-10-10');

        $row = DB::table('report_stats')->sole();

        $this->assertSame(0, $row->conversions);
        $this->assertSame('0.0000', $row->revenue);
    }

    public function test_repeated_run_gives_the_same_rows(): void
    {
        $clickId = $this->insertClick('2026-10-10 10:00:00', ['params' => ['zid' => '8841']]);
        $this->insertConversion($clickId, 'approved', '10.0000', '10.0000');
        $this->insertClick('2026-10-10 11:00:00', ['params' => ['zid' => '9120']]);

        $this->aggregateDay('2026-10-10');
        $first = DB::table('report_stats')->orderBy('hour')->get();

        $this->aggregateDay('2026-10-10');
        $second = DB::table('report_stats')->orderBy('hour')->get();

        $this->assertCount(2, $second);
        $this->assertEquals($first, $second);
    }

    public function test_status_change_is_picked_up_by_the_next_run(): void
    {
        $clickId = $this->insertClick('2026-10-10 10:00:00');
        $this->insertConversion($clickId, 'hold', '10.0000', '10.0000');

        $this->aggregateDay('2026-10-10');

        DB::table('conversions')
            ->where('click_id', '=', $clickId)
            ->update(['status' => 'approved']);

        $this->aggregateDay('2026-10-10');

        $row = DB::table('report_stats')->sole();

        $this->assertSame(0, $row->hold);
        $this->assertSame(1, $row->approved);
        $this->assertSame('10.0000', $row->revenue);
    }

    public function test_rows_outside_the_period_are_left_untouched(): void
    {
        $oldClickId = $this->insertClick('2026-10-01 10:00:00');
        $this->insertConversion($oldClickId, 'hold', '10.0000', '10.0000');
        $this->insertClick('2026-10-10 10:00:00');

        $this->aggregateDay('2026-10-01');
        $this->aggregateDay('2026-10-10');

        DB::table('conversions')
            ->where('click_id', '=', $oldClickId)
            ->update(['status' => 'approved']);

        $this->aggregateDay('2026-10-10');

        $oldRow = DB::table('report_stats')
            ->where('hour', '=', '2026-10-01 10:00:00')
            ->sole();

        $this->assertSame(1, $oldRow->hold);
        $this->assertSame(0, $oldRow->approved);
    }

    public function test_period_boundaries_are_rounded_out_to_whole_hours(): void
    {
        $this->insertClick('2026-10-10 09:59:59');
        $this->insertClick('2026-10-10 10:30:00');
        $this->insertClick('2026-10-10 11:00:00');

        $from = CarbonImmutable::parse('2026-10-10 10:15:00', 'UTC');
        $to = CarbonImmutable::parse('2026-10-10 10:45:00', 'UTC');
        $rows = app(StatsAggregator::class)->aggregate($from, $to);

        $this->assertSame(1, $rows);
        $this->assertSame('2026-10-10 10:00:00', DB::table('report_stats')->sole()->hour);
    }

    public function test_rebuild_covers_all_clicks_and_drops_rows_outside_them(): void
    {
        $this->insertClick('2026-09-01 10:00:00');
        $this->insertClick('2026-10-10 10:00:00');

        DB::table('report_stats')->insert($this->statsRow('2026-08-01 00:00:00'));
        DB::table('report_stats')->insert($this->statsRow('2026-10-11 00:00:00'));

        app(StatsAggregator::class)->rebuildAll();

        $hours = DB::table('report_stats')->orderBy('hour')->pluck('hour')->all();

        $this->assertSame(['2026-09-01 10:00:00', '2026-10-10 10:00:00'], $hours);
    }

    public function test_rebuild_without_clicks_empties_the_stats(): void
    {
        DB::table('report_stats')->insert($this->statsRow('2026-10-10 10:00:00'));

        app(StatsAggregator::class)->rebuildAll();

        $this->assertDatabaseCount('report_stats', 0);
    }

    private function aggregateDay(string $date): void
    {
        $from = CarbonImmutable::parse($date, 'UTC');
        $to = $from->addDay();

        app(StatsAggregator::class)->aggregate($from, $to);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertClick(string $clickedAt, array $attributes = []): string
    {
        $clickId = (string) Str::ulid();
        $params = $attributes['params'] ?? [];
        unset($attributes['params']);

        DB::table('clicks')->insert(array_merge([
            'click_id' => $clickId,
            'clicked_at' => $clickedAt,
            'status' => 'ok',
            'campaign_id' => $this->campaign->id,
            'offer_id' => $this->campaign->offer_id,
            'ip' => null,
            'user_agent' => null,
            'referer' => null,
            'params' => json_encode($params),
            'created_at' => $clickedAt,
        ], $attributes));

        return $clickId;
    }

    private function insertConversion(
        string $clickId,
        string $status,
        ?string $payout,
        ?string $payoutBase,
        string $changedAt = '2026-10-10 12:00:00',
    ): void {
        DB::table('conversions')->insert([
            'click_id' => $clickId,
            'cpa_network_id' => $this->network->id,
            'campaign_id' => $this->campaign->id,
            'offer_id' => $this->campaign->offer_id,
            'status' => $status,
            'payout' => $payout,
            'currency' => $payout === null ? null : 'USD',
            'payout_base' => $payoutBase,
            'base_currency' => $payoutBase === null ? null : 'USD',
            'last_postback_at' => $changedAt,
            'status_changed_at' => $changedAt,
            'created_at' => $changedAt,
            'updated_at' => $changedAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function statsRow(string $hour): array
    {
        return [
            'hour' => $hour,
            'campaign_id' => $this->campaign->id,
            'zone' => '',
            'creative' => '',
            'country_code' => '',
            'device_type' => '',
            'os_name' => '',
            'clicks' => 1,
            'unique_clicks' => 1,
            'bot_clicks' => 0,
            'conversions' => 0,
            'approved' => 0,
            'hold' => 0,
            'rejected' => 0,
            'revenue' => 0,
            'expected_revenue' => 0,
            'no_rate' => 0,
        ];
    }
}
