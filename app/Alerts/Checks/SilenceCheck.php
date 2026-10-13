<?php

namespace App\Alerts\Checks;

use App\Alerts\AlertText;
use App\Alerts\AlertType;
use App\Alerts\Check;
use App\Models\AlertSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An active campaign keeps getting clicks but no new conversions: the offer may be down.
 */
class SilenceCheck implements Check
{
    // Conversions count by when they were created: a late conversion of an old click still proves the offer works.
    private const string SQL = <<<'SQL'
        SELECT cp.id, cp.name, count(*) AS clicks
        FROM campaigns cp
        JOIN clicks k ON k.campaign_id = cp.id AND k.clicked_at >= ?
        WHERE cp.active
          AND NOT EXISTS (
              SELECT 1 FROM conversions v WHERE v.campaign_id = cp.id AND v.created_at >= ?
          )
        GROUP BY cp.id, cp.name
        HAVING count(*) >= ?
        SQL;

    public function type(): AlertType
    {
        return AlertType::SILENCE;
    }

    public function problems(AlertSetting $settings): array
    {
        $now = CarbonImmutable::now('UTC');
        $since = $now->subHours($settings->silence_hours);
        $sinceText = $since->format('Y-m-d H:i:s');
        $rows = DB::select(self::SQL, [$sinceText, $sinceText, $settings->silence_min_clicks]);
        $link = AlertText::reportsLink();
        $problems = [];

        foreach ($rows as $row) {
            $campaign = AlertText::campaign($row->name);
            $problems["campaign:{$row->id}"] = "{$campaign}: {$row->clicks} clicks and no conversions in the last {$settings->silence_hours}h\n{$link}";
        }

        return $problems;
    }
}
