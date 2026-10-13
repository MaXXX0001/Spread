<?php

namespace App\Alerts;

use App\Reports\Dimension;
use App\Reports\ReportFilter;
use App\Reports\ReportQuery;
use App\Reports\ReportRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The morning summary of one day: totals, best and worst campaigns, and gaps in the data.
 */
class DailyReport
{
    private const int CAMPAIGN_LIMIT = 3;

    private const int SCALE = 8;

    public function __construct(
        private readonly ReportQuery $reports,
    ) {}

    public function text(CarbonImmutable $day): string
    {
        $filter = new ReportFilter($day, $day);
        $total = $this->reports->total($filter);
        $campaigns = $this->reports->rows($filter->groupedBy([Dimension::CAMPAIGN]));
        $title = $day->format('D, j M Y');
        $profit = AlertText::money($total->profit());
        $roi = self::percent($total->roi());
        $revenue = AlertText::money($total->revenue);
        $cost = AlertText::money($total->cost);

        $lines = [
            "📊 <b>Spread — {$title}</b>",
            "Profit <b>{$profit}</b> · ROI {$roi}",
            "Revenue {$revenue} · Cost {$cost}",
            "Clicks {$total->clicks} · Conversions {$total->conversions}",
        ];

        $best = $campaigns
            ->filter(fn (ReportRow $row): bool => bccomp($row->profit(), '0', self::SCALE) > 0)
            ->sort(fn (ReportRow $a, ReportRow $b): int => bccomp($b->profit(), $a->profit(), self::SCALE))
            ->take(self::CAMPAIGN_LIMIT);
        $worst = $campaigns
            ->filter(fn (ReportRow $row): bool => bccomp($row->profit(), '0', self::SCALE) < 0)
            ->sort(fn (ReportRow $a, ReportRow $b): int => bccomp($a->profit(), $b->profit(), self::SCALE))
            ->take(self::CAMPAIGN_LIMIT);

        array_push($lines, ...self::campaignBlock('Top', $best));
        array_push($lines, ...self::campaignBlock('Worst', $worst));
        array_push($lines, ...$this->warnings($filter, $campaigns, $day));

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, ReportRow>  $rows
     * @return list<string>
     */
    private static function campaignBlock(string $heading, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $lines = ['', "<b>{$heading}</b>"];
        $position = 1;

        foreach ($rows as $row) {
            $name = e($row->dimension(Dimension::CAMPAIGN));
            $profit = AlertText::money($row->profit());
            $roi = self::percent($row->roi());
            $lines[] = "{$position}. {$name}: {$profit} (ROI {$roi})";
            $position++;
        }

        return $lines;
    }

    /**
     * @param  Collection<int, ReportRow>  $campaigns
     * @return list<string>
     */
    private function warnings(ReportFilter $filter, Collection $campaigns, CarbonImmutable $day): array
    {
        $missing = $this->reports->missingRates($filter);
        $date = $day->format('Y-m-d');
        $campaignsWithCosts = DB::table('costs')
            ->where('date', '=', $date)
            ->pluck('campaign_id')
            ->all();
        $withoutCosts = $campaigns
            ->filter(fn (ReportRow $row): bool => $row->clicks > 0 && ! in_array($row->campaignId, $campaignsWithCosts, true))
            ->map(fn (ReportRow $row): string => e($row->dimension(Dimension::CAMPAIGN)))
            ->values()
            ->all();
        $warnings = [];

        if ($missing['costs'] > 0) {
            $warnings[] = "⚠️ {$missing['costs']} costs have no exchange rate and are left out.";
        }

        if ($missing['conversions'] > 0) {
            $warnings[] = "⚠️ {$missing['conversions']} conversions have no exchange rate and are left out.";
        }

        if ($withoutCosts !== []) {
            $names = implode(', ', $withoutCosts);
            $warnings[] = "⚠️ Clicks but no costs: {$names}.";
        }

        return $warnings === [] ? [] : ['', ...$warnings];
    }

    private static function percent(?string $value): string
    {
        $formatted = ReportRow::format($value);

        return $formatted === null ? '—' : "{$formatted}%";
    }
}
