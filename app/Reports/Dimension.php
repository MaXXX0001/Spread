<?php

namespace App\Reports;

enum Dimension: string
{
    case CAMPAIGN = 'campaign';
    case ZONE = 'zone';
    case CREATIVE = 'creative';
    case COUNTRY = 'country';
    case DEVICE = 'device';
    case OS = 'os';
    case DAY = 'day';
    case HOUR = 'hour';

    public function label(): string
    {
        return match ($this) {
            self::CAMPAIGN => 'Campaign',
            self::ZONE => 'Zone',
            self::CREATIVE => 'Creative',
            self::COUNTRY => 'Country',
            self::DEVICE => 'Device',
            self::OS => 'OS',
            self::DAY => 'Day',
            self::HOUR => 'Hour',
        };
    }

    /**
     * The column of the cost-allocated rows in ReportQuery that holds this dimension.
     */
    public function column(): string
    {
        return match ($this) {
            self::CAMPAIGN => 'campaign_id',
            self::ZONE => 'zone',
            self::CREATIVE => 'creative',
            self::COUNTRY => 'country_code',
            self::DEVICE => 'device_type',
            self::OS => 'os_name',
            self::DAY => 'day',
            self::HOUR => 'hour_of_day',
        };
    }
}
