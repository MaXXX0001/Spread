<?php

namespace App\Alerts;

use App\Models\AlertSetting;

enum AlertType: string
{
    case ROI = 'roi';
    case SILENCE = 'silence';
    case HIGH_CR = 'high_cr';
    case BUFFER = 'buffer';
    case ERRORS = 'errors';

    public function title(): string
    {
        return match ($this) {
            self::ROI => 'Low ROI',
            self::SILENCE => 'No conversions',
            self::HIGH_CR => 'High CR',
            self::BUFFER => 'Buffer not drained',
            self::ERRORS => 'Processing errors',
        };
    }

    public function isEnabled(AlertSetting $settings): bool
    {
        return match ($this) {
            self::ROI => $settings->roi_enabled,
            self::SILENCE => $settings->silence_enabled,
            self::HIGH_CR => $settings->cr_enabled,
            self::BUFFER => $settings->buffer_enabled,
            self::ERRORS => $settings->errors_enabled,
        };
    }
}
