<?php

namespace App\Alerts\Checks;

use App\Alerts\AlertText;
use App\Alerts\AlertType;
use App\Alerts\Check;
use App\Models\AlertSetting;
use App\Models\Setting;
use App\Reports\Dimension;
use App\Reports\ReportFilter;
use App\Reports\ReportQuery;
use App\Reports\ReportRow;
use Carbon\CarbonImmutable;

/**
 * A campaign spent at least the threshold today and its ROI today is below the limit.
 */
class RoiCheck implements Check
{
    private const int SCALE = 8;

    public function __construct(
        private readonly ReportQuery $reports,
    ) {}

    public function type(): AlertType
    {
        return AlertType::ROI;
    }

    public function problems(AlertSetting $settings): array
    {
        $today = CarbonImmutable::today(Setting::current()->report_timezone);
        $filter = new ReportFilter($today, $today, [], [Dimension::CAMPAIGN]);
        $minCost = (string) $settings->roi_min_cost;
        $limit = (string) $settings->roi_max;
        $problems = [];

        foreach ($this->reports->rows($filter) as $row) {
            $roi = $row->roi();
            $hasEnoughCost = bccomp($row->cost, $minCost, self::SCALE) >= 0;
            $isBelowLimit = $roi !== null && bccomp($roi, $limit, self::SCALE) < 0;

            if (
                $hasEnoughCost
                && $isBelowLimit
            ) {
                $problems["campaign:{$row->campaignId}"] = $this->describe($row, $limit);
            }
        }

        return $problems;
    }

    private function describe(ReportRow $row, string $limit): string
    {
        $campaign = AlertText::campaign($row->dimension(Dimension::CAMPAIGN));
        $cost = AlertText::money($row->cost);
        $revenue = AlertText::money($row->revenue);
        $roi = ReportRow::format($row->roi());
        $link = AlertText::reportsLink();

        return "{$campaign}: ROI {$roi}% today (limit {$limit}%)\nCost {$cost} · Revenue {$revenue}\n{$link}";
    }
}
