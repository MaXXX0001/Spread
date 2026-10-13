<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CpaNetwork;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Reports\Dimension;
use App\Reports\ReportFilter;
use App\Reports\ReportQuery;
use App\Reports\ReportRow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportQueryTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    private Campaign $otherCampaign;

    protected function setUp(): void
    {
        parent::setUp();

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
            'name' => 'PH / Nutra UA',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);
        $this->otherCampaign = Campaign::create([
            'name' => 'PH / Nutra PL',
            'traffic_source_id' => $source->id,
            'offer_id' => $offer->id,
        ]);
    }

    public function test_metrics_of_a_row(): void
    {
        $this->insertStats('2026-10-10 10:00:00', [
            'clicks' => 200,
            'conversions' => 4,
            'approved' => 3,
            'hold' => 1,
            'revenue' => '30',
            'expected_revenue' => '10',
        ]);
        $this->insertCost('2026-10-10', null, '20');

        $row = $this->rows([Dimension::CAMPAIGN])->sole();

        $this->assertSame('PH / Nutra UA', $row->dimension(Dimension::CAMPAIGN));
        $this->assertSame($this->campaign->id, $row->campaignId);
        $this->assertSame(200, $row->clicks);
        $this->assertSame(4, $row->conversions);
        $this->assertSame('2.00', $this->round($row->cr()));
        $this->assertSame('30.00', $this->round($row->revenue));
        $this->assertSame('10.00', $this->round($row->expectedRevenue));
        $this->assertSame('20.00', $this->round($row->cost));
        $this->assertSame('10.00', $this->round($row->profit()));
        $this->assertSame('50.00', $this->round($row->roi()));
        $this->assertSame('0.15', $this->round($row->epc()));
    }

    public function test_row_without_cost_has_no_roi(): void
    {
        $this->insertStats('2026-10-10 10:00:00', ['clicks' => 10, 'revenue' => '12']);

        $row = $this->rows([Dimension::CAMPAIGN])->sole();

        $this->assertSame('12.00', $this->round($row->profit()));
        $this->assertNull($row->roi());
    }

    public function test_campaign_cost_is_spread_over_creatives_by_clicks(): void
    {
        $this->insertStats('2026-10-10 10:00:00', ['creative' => 'A', 'clicks' => 200]);
        $this->insertStats('2026-10-10 15:00:00', ['creative' => 'B', 'clicks' => 100]);
        $this->insertCost('2026-10-10', null, '30');

        $costs = $this->rows([Dimension::CREATIVE])
            ->mapWithKeys(fn (ReportRow $row): array => [$row->dimension(Dimension::CREATIVE) => $this->round($row->cost)])
            ->all();

        $this->assertEquals(['A' => '20.00', 'B' => '10.00'], $costs);
    }

    public function test_zone_cost_is_spread_only_over_its_zone(): void
    {
        $this->insertStats('2026-10-10 10:00:00', ['zone' => '8841', 'creative' => 'A', 'clicks' => 30]);
        $this->insertStats('2026-10-10 10:00:00', ['zone' => '8841', 'creative' => 'B', 'clicks' => 10]);
        $this->insertStats('2026-10-10 10:00:00', ['zone' => '9120', 'creative' => 'A', 'clicks' => 60]);
        $this->insertCost('2026-10-10', '8841', '8');

        $costs = $this->rows([Dimension::ZONE, Dimension::CREATIVE])
            ->mapWithKeys(fn (ReportRow $row): array => [
                $row->dimension(Dimension::ZONE).'/'.$row->dimension(Dimension::CREATIVE) => $this->round($row->cost),
            ])
            ->all();

        $this->assertEquals(['8841/A' => '6.00', '8841/B' => '2.00', '9120/A' => '0.00'], $costs);
    }

    public function test_zone_cost_without_clicks_gets_its_own_row(): void
    {
        $this->insertStats('2026-10-10 10:00:00', ['zone' => '8841', 'clicks' => 10]);
        $this->insertCost('2026-10-10', '9120', '5');

        $row = $this->rows([Dimension::ZONE])
            ->first(fn (ReportRow $row): bool => $row->dimension(Dimension::ZONE) === '9120');

        $this->assertSame(0, $row->clicks);
        $this->assertSame('5.00', $this->round($row->cost));
        $this->assertSame('-5.00', $this->round($row->profit()));
        $this->assertNull($row->cr());
        $this->assertNull($row->epc());
    }

    public function test_total_cost_does_not_depend_on_grouping(): void
    {
        $this->insertStats('2026-10-10 10:00:00', ['zone' => '8841', 'country_code' => 'UA', 'clicks' => 7]);
        $this->insertStats('2026-10-10 18:00:00', ['zone' => '9120', 'country_code' => 'PL', 'clicks' => 5]);
        $this->insertStats('2026-10-11 09:00:00', ['country_code' => 'UA', 'clicks' => 3], $this->otherCampaign);
        $this->insertCost('2026-10-10', '8841', '10');
        $this->insertCost('2026-10-10', '9120', '3.3333');
        $this->insertCost('2026-10-10', '7001', '1');
        $this->insertCost('2026-10-11', null, '7', $this->otherCampaign);
        $this->insertCost('2026-10-12', null, '2', $this->otherCampaign);

        $groupings = [
            [],
            [Dimension::CAMPAIGN],
            [Dimension::COUNTRY],
            [Dimension::HOUR],
            [Dimension::CAMPAIGN, Dimension::ZONE],
            [Dimension::DAY, Dimension::CREATIVE],
        ];

        foreach ($groupings as $groupBy) {
            $sum = $this->rows($groupBy)->reduce(fn (string $sum, ReportRow $row): string => bcadd($sum, $row->cost, 8), '0');

            $this->assertSame('23.33', $this->round($sum));
        }
    }

    public function test_cost_without_base_amount_is_left_out_and_reported(): void
    {
        $this->insertStats('2026-10-10 10:00:00', ['clicks' => 10, 'no_rate' => 2]);
        $this->insertCost('2026-10-10', null, null);

        $query = app(ReportQuery::class);
        $filter = $this->filter([]);
        $total = $query->total($filter);

        $this->assertSame('0.00', $this->round($total->cost));
        $this->assertSame(['costs' => 1, 'conversions' => 2], $query->missingRates($filter));
    }

    public function test_campaign_filter_limits_stats_and_costs(): void
    {
        $this->insertStats('2026-10-10 10:00:00', ['clicks' => 10]);
        $this->insertStats('2026-10-10 10:00:00', ['clicks' => 4], $this->otherCampaign);
        $this->insertCost('2026-10-10', null, '5');
        $this->insertCost('2026-10-10', null, '9', $this->otherCampaign);
        $this->insertCost('2026-10-11', null, null, $this->otherCampaign);

        $query = app(ReportQuery::class);
        $filter = $this->filter([], [$this->campaign->id]);
        $total = $query->total($filter);

        $this->assertSame(10, $total->clicks);
        $this->assertSame('5.00', $this->round($total->cost));
        $this->assertSame(['costs' => 0, 'conversions' => 0], $query->missingRates($filter));
    }

    public function test_day_and_hour_are_in_report_timezone(): void
    {
        // 22:00 UTC on Oct 10 is 01:00 on Oct 11 in Kyiv.
        $this->insertStats('2026-10-10 22:00:00', ['clicks' => 1]);

        $row = $this->rows([Dimension::DAY, Dimension::HOUR], '2026-10-11', '2026-10-11')->sole();

        $this->assertSame('2026-10-11', $row->dimension(Dimension::DAY));
        $this->assertSame('1', $row->dimension(Dimension::HOUR));
    }

    public function test_period_is_whole_days_in_report_timezone(): void
    {
        $this->insertStats('2026-10-04 20:00:00', ['clicks' => 1]);
        $this->insertStats('2026-10-04 21:00:00', ['clicks' => 2]);
        $this->insertStats('2026-10-07 20:00:00', ['clicks' => 4]);
        $this->insertStats('2026-10-07 21:00:00', ['clicks' => 8]);
        $this->insertCost('2026-10-04', null, '1');
        $this->insertCost('2026-10-05', null, '2');
        $this->insertCost('2026-10-08', null, '4');

        $total = app(ReportQuery::class)->total($this->filter([], [], '2026-10-05', '2026-10-07'));

        $this->assertSame(6, $total->clicks);
        $this->assertSame('2.00', $this->round($total->cost));
    }

    public function test_two_levels_give_a_row_per_pair(): void
    {
        $this->insertStats('2026-10-10 10:00:00', ['zone' => '8841', 'clicks' => 3]);
        $this->insertStats('2026-10-10 11:00:00', ['zone' => '8841', 'clicks' => 2]);
        $this->insertStats('2026-10-10 10:00:00', ['zone' => '9120', 'clicks' => 1]);
        $this->insertStats('2026-10-10 10:00:00', ['zone' => '8841', 'clicks' => 4], $this->otherCampaign);

        $clicks = $this->rows([Dimension::CAMPAIGN, Dimension::ZONE])
            ->mapWithKeys(fn (ReportRow $row): array => [
                $row->dimension(Dimension::CAMPAIGN).'/'.$row->dimension(Dimension::ZONE) => $row->clicks,
            ])
            ->all();

        $this->assertEquals([
            'PH / Nutra UA/8841' => 5,
            'PH / Nutra UA/9120' => 1,
            'PH / Nutra PL/8841' => 4,
        ], $clicks);
    }

    public function test_empty_period_gives_a_zero_total(): void
    {
        $total = app(ReportQuery::class)->total($this->filter([]));

        $this->assertSame(0, $total->clicks);
        $this->assertSame('0.00', $this->round($total->cost));
        $this->assertNull($total->roi());
    }

    /**
     * @param  list<Dimension>  $groupBy
     * @return Collection<int, ReportRow>
     */
    private function rows(array $groupBy, string $from = '2026-10-01', string $to = '2026-10-31'): Collection
    {
        return app(ReportQuery::class)->rows($this->filter($groupBy, [], $from, $to));
    }

    /**
     * @param  list<Dimension>  $groupBy
     * @param  list<int>  $campaignIds
     */
    private function filter(array $groupBy, array $campaignIds = [], string $from = '2026-10-01', string $to = '2026-10-31'): ReportFilter
    {
        return new ReportFilter(
            from: CarbonImmutable::parse($from),
            to: CarbonImmutable::parse($to),
            campaignIds: $campaignIds,
            groupBy: $groupBy,
        );
    }

    private function round(?string $value): string
    {
        $rounded = round((float) $value, 2);

        return number_format($rounded, 2, '.', '');
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

    private function insertCost(string $date, ?string $zone, ?string $amountBase, ?Campaign $campaign = null): void
    {
        $campaign ??= $this->campaign;

        DB::table('costs')->insert([
            'campaign_id' => $campaign->id,
            'date' => $date,
            'zone' => $zone,
            'amount' => $amountBase ?? '10',
            'currency' => $amountBase === null ? 'PLN' : 'USD',
            'amount_base' => $amountBase,
            'base_currency' => $amountBase === null ? null : 'USD',
        ]);
    }
}
