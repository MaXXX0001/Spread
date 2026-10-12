<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Report settings the admin changes without code: the base currency and the report timezone.
 */
#[Fillable(['base_currency', 'report_timezone'])]
class Setting extends Model
{
    public const string DEFAULT_BASE_CURRENCY = 'USD';

    public const string DEFAULT_REPORT_TIMEZONE = 'Europe/Kyiv';

    /**
     * The saved row, or an unsaved one with the defaults, so callers never see missing settings.
     */
    public static function current(): self
    {
        $saved = self::query()->first();

        return $saved ?? new self([
            'base_currency' => self::DEFAULT_BASE_CURRENCY,
            'report_timezone' => self::DEFAULT_REPORT_TIMEZONE,
        ]);
    }
}
