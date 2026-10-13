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
 * A campaign has enough clicks today and a CR above the limit: likely fraud or a bug.
 */
class HighCrCheck implements Check
{
    private const int SCALE = 8;

    public function __construct(
        private readonly ReportQuery $reports,
    ) {}

    public function type(): AlertType
    {
        return AlertType::HIGH_CR;
    }

    public function problems(AlertSetting $settings): array
    {
        $today = CarbonImmutable::today(Setting::current()->report_timezone);
        $filter = new ReportFilter($today, $today, [], [Dimension::CAMPAIGN]);
        $limit = (string) $settings->cr_max;
        $problems = [];

        foreach ($this->reports->rows($filter) as $row) {
            $cr = $row->cr();
            $hasEnoughClicks = $row->clicks >= $settings->cr_min_clicks;
            $isAboveLimit = $cr !== null && bccomp($cr, $limit, self::SCALE) > 0;

            if (
                $hasEnoughClicks
                && $isAboveLimit
            ) {
                $problems["campaign:{$row->campaignId}"] = $this->describe($row, $limit);
            }
        }

        return $problems;
    }

    private function describe(ReportRow $row, string $limit): string
    {
        $campaign = AlertText::campaign($row->dimension(Dimension::CAMPAIGN));
        $cr = ReportRow::format($row->cr());
        $link = AlertText::reportsLink();

        return "{$campaign}: CR {$cr}% today (limit {$limit}%)\n{$row->conversions} conversions from {$row->clicks} clicks\n{$link}";
    }
}
