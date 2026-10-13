<?php

namespace App\Reports;

use App\Models\Campaign;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Builds report rows from report_stats and costs, spreading each cost over its rows by clicks.
 */
final class ReportQuery
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    private const string DATE_FORMAT = 'Y-m-d';

    /**
     * p holds the parameters once, so the SQL uses each binding a single time.
     * f: stats rows by every dimension, with the day and hour in the report timezone.
     * zc / cc: costs of a zone / of the whole campaign per day.
     * allocated: f with its share of costs by clicks, plus costs that have no clicks to spread over.
     */
    private const string ALLOCATED_SQL = <<<'SQL'
        WITH p AS (
            SELECT ?::timestamp AS from_utc, ?::timestamp AS to_utc, ?::date AS from_day, ?::date AS to_day,
                   ?::text AS tz, ?::bigint[] AS campaign_ids
        ),
        f AS (
            SELECT s.campaign_id, s.zone, s.creative, s.country_code, s.device_type, s.os_name,
                   (s.hour AT TIME ZONE 'UTC' AT TIME ZONE p.tz)::date AS day,
                   extract(hour FROM s.hour AT TIME ZONE 'UTC' AT TIME ZONE p.tz)::int AS hour_of_day,
                   sum(s.clicks) AS clicks, sum(s.unique_clicks) AS unique_clicks, sum(s.bot_clicks) AS bot_clicks,
                   sum(s.conversions) AS conversions, sum(s.approved) AS approved, sum(s.hold) AS hold,
                   sum(s.rejected) AS rejected, sum(s.revenue) AS revenue, sum(s.expected_revenue) AS expected_revenue
            FROM report_stats s CROSS JOIN p
            WHERE s.hour >= p.from_utc AND s.hour < p.to_utc
              AND (p.campaign_ids IS NULL OR s.campaign_id = ANY (p.campaign_ids))
            GROUP BY 1, 2, 3, 4, 5, 6, 7, 8
        ),
        fw AS (
            SELECT f.*,
                   sum(f.clicks) OVER (PARTITION BY f.campaign_id, f.day, f.zone) AS zone_clicks,
                   sum(f.clicks) OVER (PARTITION BY f.campaign_id, f.day) AS campaign_clicks
            FROM f
        ),
        zc AS (
            SELECT c.campaign_id, c.date AS day, c.zone, sum(c.amount_base) AS amount
            FROM costs c CROSS JOIN p
            WHERE c.amount_base IS NOT NULL AND c.zone IS NOT NULL
              AND c.date BETWEEN p.from_day AND p.to_day
              AND (p.campaign_ids IS NULL OR c.campaign_id = ANY (p.campaign_ids))
            GROUP BY 1, 2, 3
        ),
        cc AS (
            SELECT c.campaign_id, c.date AS day, sum(c.amount_base) AS amount
            FROM costs c CROSS JOIN p
            WHERE c.amount_base IS NOT NULL AND c.zone IS NULL
              AND c.date BETWEEN p.from_day AND p.to_day
              AND (p.campaign_ids IS NULL OR c.campaign_id = ANY (p.campaign_ids))
            GROUP BY 1, 2
        ),
        allocated AS (
            SELECT fw.campaign_id, fw.zone, fw.creative, fw.country_code, fw.device_type, fw.os_name,
                   fw.day, fw.hour_of_day, fw.clicks, fw.unique_clicks, fw.bot_clicks, fw.conversions,
                   fw.approved, fw.hold, fw.rejected, fw.revenue, fw.expected_revenue,
                   coalesce(zc.amount * fw.clicks / fw.zone_clicks, 0)
                       + coalesce(cc.amount * fw.clicks / fw.campaign_clicks, 0) AS cost
            FROM fw
            LEFT JOIN zc ON zc.campaign_id = fw.campaign_id AND zc.day = fw.day AND zc.zone = fw.zone
            LEFT JOIN cc ON cc.campaign_id = fw.campaign_id AND cc.day = fw.day
            UNION ALL
            SELECT zc.campaign_id, zc.zone, '', '', '', '', zc.day, NULL, 0, 0, 0, 0, 0, 0, 0, 0, 0, zc.amount
            FROM zc
            WHERE NOT EXISTS (
                SELECT 1 FROM f WHERE f.campaign_id = zc.campaign_id AND f.day = zc.day AND f.zone = zc.zone
            )
            UNION ALL
            SELECT cc.campaign_id, '', '', '', '', '', cc.day, NULL, 0, 0, 0, 0, 0, 0, 0, 0, 0, cc.amount
            FROM cc
            WHERE NOT EXISTS (
                SELECT 1 FROM f WHERE f.campaign_id = cc.campaign_id AND f.day = cc.day
            )
        )
        SQL;

    private const string SUMS_SQL = <<<'SQL'
        coalesce(sum(clicks), 0) AS clicks, coalesce(sum(unique_clicks), 0) AS unique_clicks,
        coalesce(sum(bot_clicks), 0) AS bot_clicks, coalesce(sum(conversions), 0) AS conversions,
        coalesce(sum(approved), 0) AS approved, coalesce(sum(hold), 0) AS hold,
        coalesce(sum(rejected), 0) AS rejected, coalesce(sum(revenue), 0) AS revenue,
        coalesce(sum(expected_revenue), 0) AS expected_revenue, coalesce(sum(cost), 0) AS cost
        SQL;

    /**
     * @return Collection<int, ReportRow>
     */
    public function rows(ReportFilter $filter): Collection
    {
        $columns = array_map(fn (Dimension $dimension): string => $dimension->column(), $filter->groupBy);
        $selectedColumns = $columns === [] ? '' : implode(', ', $columns).', ';
        $groupBy = $columns === [] ? '' : 'GROUP BY '.implode(', ', $columns);
        $sql = self::ALLOCATED_SQL."\nSELECT {$selectedColumns}".self::SUMS_SQL."\nFROM allocated {$groupBy}";
        $bindings = $this->bindings($filter);
        $records = DB::select($sql, $bindings);
        $campaignNames = $this->campaignNames($records, $filter);

        return collect($records)
            ->map(fn (stdClass $record): ReportRow => $this->toRow($record, $filter, $campaignNames));
    }

    /**
     * The total row of the filter, whatever its grouping.
     */
    public function total(ReportFilter $filter): ReportRow
    {
        $ungrouped = $filter->groupedBy([]);
        $rows = $this->rows($ungrouped);

        return $rows->sole();
    }

    /**
     * Costs and conversions of the period whose amounts are left out for lack of a rate.
     *
     * @return array{costs: int, conversions: int}
     */
    public function missingRates(ReportFilter $filter): array
    {
        [$fromUtc, $toUtc] = $this->utcBounds($filter);
        $from = $filter->from->format(self::DATE_FORMAT);
        $to = $filter->to->format(self::DATE_FORMAT);

        $costs = DB::table('costs')
            ->whereNull('amount_base')
            ->whereBetween('date', [$from, $to])
            ->when($filter->campaignIds !== [], fn ($query) => $query->whereIn('campaign_id', $filter->campaignIds))
            ->count();
        $conversions = DB::table('report_stats')
            ->where('hour', '>=', $fromUtc)
            ->where('hour', '<', $toUtc)
            ->when($filter->campaignIds !== [], fn ($query) => $query->whereIn('campaign_id', $filter->campaignIds))
            ->sum('no_rate');

        return [
            'costs' => $costs,
            'conversions' => (int) $conversions,
        ];
    }

    /**
     * @return list<string|null>
     */
    private function bindings(ReportFilter $filter): array
    {
        [$fromUtc, $toUtc] = $this->utcBounds($filter);
        $campaignIds = $filter->campaignIds === [] ? null : '{'.implode(',', $filter->campaignIds).'}';

        return [
            $fromUtc,
            $toUtc,
            $filter->from->format(self::DATE_FORMAT),
            $filter->to->format(self::DATE_FORMAT),
            Setting::current()->report_timezone,
            $campaignIds,
        ];
    }

    /**
     * @return array{string, string} UTC start of the first day and of the day after the last one.
     */
    private function utcBounds(ReportFilter $filter): array
    {
        $timezone = Setting::current()->report_timezone;
        $fromDate = $filter->from->format(self::DATE_FORMAT);
        $dayAfterTo = $filter->to->addDay();
        $toDate = $dayAfterTo->format(self::DATE_FORMAT);
        $fromLocal = CarbonImmutable::parse($fromDate, $timezone);
        $toLocal = CarbonImmutable::parse($toDate, $timezone);
        $fromUtc = $fromLocal->utc();
        $toUtc = $toLocal->utc();

        return [
            $fromUtc->format(self::TIMESTAMP_FORMAT),
            $toUtc->format(self::TIMESTAMP_FORMAT),
        ];
    }

    /**
     * @param  list<stdClass>  $records
     * @return array<int, string>
     */
    private function campaignNames(array $records, ReportFilter $filter): array
    {
        if (! in_array(Dimension::CAMPAIGN, $filter->groupBy, true)) {
            return [];
        }

        $ids = array_map(fn (stdClass $record): int => (int) $record->campaign_id, $records);

        return Campaign::query()
            ->whereIn('id', array_unique($ids))
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @param  array<int, string>  $campaignNames
     */
    private function toRow(stdClass $record, ReportFilter $filter, array $campaignNames): ReportRow
    {
        $dimensions = [];
        $campaignId = null;

        foreach ($filter->groupBy as $dimension) {
            $value = $record->{$dimension->column()};

            if ($dimension === Dimension::CAMPAIGN) {
                $campaignId = (int) $value;
                $value = $campaignNames[$campaignId] ?? "#{$campaignId}";
            }

            $dimensions[$dimension->value] = $value === null ? '' : (string) $value;
        }

        return new ReportRow(
            dimensions: $dimensions,
            campaignId: $campaignId,
            clicks: (int) $record->clicks,
            uniqueClicks: (int) $record->unique_clicks,
            botClicks: (int) $record->bot_clicks,
            conversions: (int) $record->conversions,
            approved: (int) $record->approved,
            hold: (int) $record->hold,
            rejected: (int) $record->rejected,
            revenue: (string) $record->revenue,
            expectedRevenue: (string) $record->expected_revenue,
            cost: (string) $record->cost,
        );
    }
}
