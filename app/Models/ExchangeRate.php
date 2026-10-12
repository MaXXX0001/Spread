<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * How many hryvnias one unit of a currency costs on a date; UAH itself has no row, its rate is always 1.
 */
#[Fillable(['date', 'currency', 'uah_per_unit', 'source'])]
class ExchangeRate extends Model
{
    public const string SOURCE_NBU = 'nbu';

    public const string SOURCE_MANUAL = 'manual';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'uah_per_unit' => 'decimal:8',
        ];
    }
}
