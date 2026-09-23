<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cpa_network_id', 'name', 'url_template'])]
class Offer extends Model
{
    /**
     * @return BelongsTo<CpaNetwork, $this>
     */
    public function cpaNetwork(): BelongsTo
    {
        return $this->belongsTo(CpaNetwork::class);
    }
}
