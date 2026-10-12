<?php

namespace App\Models;

use App\Postbacks\ConversionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversion extends Model
{
    /**
     * Keeps milliseconds: the stale-postback check compares last_postback_at with postback times.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConversionStatus::class,
            'payout' => 'decimal:4',
            'last_postback_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Click, $this>
     */
    public function click(): BelongsTo
    {
        return $this->belongsTo(Click::class, 'click_id', 'click_id');
    }

    /**
     * @return BelongsTo<CpaNetwork, $this>
     */
    public function cpaNetwork(): BelongsTo
    {
        return $this->belongsTo(CpaNetwork::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return HasMany<ConversionStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(ConversionStatusChange::class);
    }
}
