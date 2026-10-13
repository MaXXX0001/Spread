<?php

namespace App\Alerts;

use App\Models\Setting;
use App\Reports\ReportRow;

/**
 * Pieces of alert messages shared by the checks; every value is already HTML-safe.
 */
final class AlertText
{
    public static function money(string $amount): string
    {
        $currency = Setting::current()->base_currency;
        $formatted = ReportRow::format($amount);

        return "{$formatted} {$currency}";
    }

    public static function campaign(string $name): string
    {
        $escaped = e($name);

        return "<b>{$escaped}</b>";
    }

    public static function reportsLink(): string
    {
        $url = rtrim((string) config('app.url'), '/').'/admin/reports';
        $escaped = e($url);

        return "<a href=\"{$escaped}\">Reports</a>";
    }
}
