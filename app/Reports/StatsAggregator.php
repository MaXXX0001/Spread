<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the hourly report_stats rows from clicks and their conversions.
 */
class StatsAggregator
{
    private const string LOCK = 'spread:reports:aggregate';

    private const int LOCK_SECONDS = 3600;

    private const int LOCK_WAIT_SECONDS = 600;

    private const string INSERT_SQL = <<<'SQL'
        INSERT INTO report_stats (
            hour, campaign_id, zone, creative, country_code, device_type, os_name,
            clicks, unique_clicks, bot_clicks, conversions, approved, hold, rejected,
            revenue, expected_revenue, no_rate
        )
        SELECT
            date_trunc('hour', c.clicked_at),
            c.campaign_id,
            coalesce(c.params ->> ts.zone_param, ''),
            coalesce(c.params ->> ts.creative_param, ''),
            coalesce(c.country_code, ''),
            coalesce(c.device_type, ''),
            coalesce(c.os_name, ''),
            count(*),
            count(*) FILTER (WHERE NOT c.is_duplicate),
            count(*) FILTER (WHERE c.is_bot),
            count(v.id) FILTER (WHERE v.status IN ('lead', 'hold', 'approved')),
            count(v.id) FILTER (WHERE v.status = 'approved'),
            count(v.id) FILTER (WHERE v.status = 'hold'),
            count(v.id) FILTER (WHERE v.status = 'rejected'),
            coalesce(sum(v.payout_base) FILTER (WHERE v.status = 'approved'), 0),
            coalesce(sum(v.payout_base) FILTER (WHERE v.status = 'hold'), 0),
            count(v.id) FILTER (WHERE v.status IN ('approved', 'hold') AND v.payout IS NOT NULL AND v.payout_base IS NULL)
        FROM clicks c
        LEFT JOIN campaigns cp ON cp.id = c.campaign_id
        LEFT JOIN traffic_sources ts ON ts.id = cp.traffic_source_id
        LEFT JOIN conversions v ON v.click_id = c.click_id
        WHERE c.clicked_at >= ? AND c.clicked_at < ?
        GROUP BY 1, 2, 3, 4, 5, 6, 7
        SQL;

    /**
     * Replaces the rows of every hour that overlaps [from, to); returns the number of rows written.
     */
    public function aggregate(CarbonImmutable $fromUtc, CarbonImmutable $toUtc): int
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        return $lock->block(self::LOCK_WAIT_SECONDS, fn (): int => $this->aggregateHours($fromUtc, $toUtc));
    }

    /**
     * Rebuilds the stats for the whole time clicks exist and drops rows outside it.
     */
    public function rebuildAll(): int
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        return $lock->block(self::LOCK_WAIT_SECONDS, function (): int {
            $range = DB::table('clicks')
                ->selectRaw('min(clicked_at) AS first, max(clicked_at) AS last')
                ->first();

            if ($range->first === null) {
                DB::table('report_stats')->delete();

                return 0;
            }

            $first = CarbonImmutable::parse($range->first, 'UTC')->startOfHour();
            $lastHour = CarbonImmutable::parse($range->last, 'UTC')->startOfHour();
            $end = $lastHour->addHour();

            DB::table('report_stats')
                ->where('hour', '<', $first)
                ->orWhere('hour', '>=', $end)
                ->delete();

            return $this->aggregateHours($first, $end);
        });
    }

    private function aggregateHours(CarbonImmutable $fromUtc, CarbonImmutable $toUtc): int
    {
        $start = $fromUtc->setTimezone('UTC')->startOfHour();
        $end = $toUtc->setTimezone('UTC');
        $endHour = $end->startOfHour();

        if ($endHour->lt($end)) {
            $end = $endHour->addHour();
        }

        $rows = 0;

        // One transaction per day keeps a full rebuild from holding one huge transaction.
        while ($start->lt($end)) {
            $nextDay = $start->addDay();
            $chunkEnd = $nextDay->min($end);
            $rows += $this->replaceChunk($start, $chunkEnd);
            $start = $chunkEnd;
        }

        return $rows;
    }

    private function replaceChunk(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return DB::transaction(function () use ($from, $to): int {
            DB::table('report_stats')
                ->where('hour', '>=', $from)
                ->where('hour', '<', $to)
                ->delete();

            return DB::affectingStatement(self::INSERT_SQL, [$from, $to]);
        });
    }
}
