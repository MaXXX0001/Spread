<?php

namespace App\Models;

use App\ConfigSnapshot\ConfigSnapshotObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['cpa_network_id', 'name', 'url_template'])]
#[ObservedBy(ConfigSnapshotObserver::class)]
class Offer extends Model
{
    /**
     * @return BelongsTo<CpaNetwork, $this>
     */
    public function cpaNetwork(): BelongsTo
    {
        return $this->belongsTo(CpaNetwork::class);
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
