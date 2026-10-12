<?php

namespace App\Models;

use App\Postbacks\ConversionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversionStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => ConversionStatus::class,
            'to_status' => ConversionStatus::class,
            'payout' => 'decimal:4',
            'changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversion, $this>
     */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(Conversion::class);
    }

    /**
     * @return BelongsTo<Postback, $this>
     */
    public function postback(): BelongsTo
    {
        return $this->belongsTo(Postback::class, 'postback_id', 'postback_id');
    }
}
