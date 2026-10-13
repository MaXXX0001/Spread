<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Alert thresholds and delivery settings the admin changes without code; the bot token stays in the environment.
 */
#[Fillable([
    'chat_id',
    'reminder_hours',
    'roi_enabled',
    'roi_min_cost',
    'roi_max',
    'silence_enabled',
    'silence_hours',
    'silence_min_clicks',
    'cr_enabled',
    'cr_max',
    'cr_min_clicks',
    'buffer_enabled',
    'buffer_max_age_minutes',
    'buffer_max_length',
    'errors_enabled',
    'daily_enabled',
    'daily_time',
    'daily_sent_on',
])]
class AlertSetting extends Model
{
    public const array DEFAULTS = [
        'chat_id' => null,
        'reminder_hours' => 6,
        'roi_enabled' => true,
        'roi_min_cost' => '20.0000',
        'roi_max' => '-30.00',
        'silence_enabled' => true,
        'silence_hours' => 6,
        'silence_min_clicks' => 100,
        'cr_enabled' => true,
        'cr_max' => '20.00',
        'cr_min_clicks' => 50,
        'buffer_enabled' => true,
        'buffer_max_age_minutes' => 5,
        'buffer_max_length' => 10000,
        'errors_enabled' => true,
        'daily_enabled' => true,
        'daily_time' => '09:00',
        'daily_sent_on' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'reminder_hours' => 'integer',
            'roi_enabled' => 'boolean',
            'roi_min_cost' => 'decimal:4',
            'roi_max' => 'decimal:2',
            'silence_enabled' => 'boolean',
            'silence_hours' => 'integer',
            'silence_min_clicks' => 'integer',
            'cr_enabled' => 'boolean',
            'cr_max' => 'decimal:2',
            'cr_min_clicks' => 'integer',
            'buffer_enabled' => 'boolean',
            'buffer_max_age_minutes' => 'integer',
            'buffer_max_length' => 'integer',
            'errors_enabled' => 'boolean',
            'daily_enabled' => 'boolean',
            'daily_sent_on' => 'immutable_date',
        ];
    }

    /**
     * The saved row, or an unsaved one with the defaults, so callers never see missing settings.
     */
    public static function current(): self
    {
        $saved = self::query()->first();

        return $saved ?? new self(self::DEFAULTS);
    }
}
