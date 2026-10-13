<?php

namespace App\Models;

use App\Jobs\RebuildReportStats;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'macros', 'zone_param', 'creative_param'])]
class TrafficSource extends Model
{
    protected static function booted(): void
    {
        // Report stats take the zone and creative of every past click from these params.
        static::saved(function (TrafficSource $source): void {
            if ($source->wasChanged(['zone_param', 'creative_param'])) {
                RebuildReportStats::dispatch();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'macros' => 'array',
        ];
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
