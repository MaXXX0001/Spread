<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'traffic_source_id', 'offer_id', 'active'])]
class Campaign extends Model
{
    private const ALIAS_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private const ALIAS_LENGTH = 10;

    protected static function booted(): void
    {
        static::creating(function (Campaign $campaign): void {
            $campaign->alias = self::generateUniqueAlias();
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
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TrafficSource, $this>
     */
    public function trafficSource(): BelongsTo
    {
        return $this->belongsTo(TrafficSource::class);
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * Macros are joined as is: URL-encoding would turn {zoneid} into %7Bzoneid%7D and the ad network would not substitute it.
     */
    public function trackingUrl(): string
    {
        $trackerUrl = config('spread.tracker_url');
        $baseUrl = rtrim($trackerUrl, '/');
        $pairs = array_map(
            fn (array $pair): string => $pair['param'].'='.$pair['macro'],
            $this->trafficSource->macros,
        );
        $query = implode('&', $pairs);

        return "{$baseUrl}/c/{$this->alias}?{$query}";
    }

    private static function generateUniqueAlias(): string
    {
        do {
            $alias = self::generateAlias();
            $isTaken = self::query()->where('alias', '=', $alias)->exists();
        } while ($isTaken);

        return $alias;
    }

    private static function generateAlias(): string
    {
        $maxIndex = strlen(self::ALIAS_ALPHABET) - 1;
        $alias = '';

        for ($i = 0; $i < self::ALIAS_LENGTH; $i++) {
            $index = random_int(0, $maxIndex);
            $alias .= self::ALIAS_ALPHABET[$index];
        }

        return $alias;
    }
}
