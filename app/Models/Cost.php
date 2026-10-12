<?php

namespace App\Models;

use App\Money\CurrencyConverter;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a campaign (or one of its zones) cost for a day, with the amount in the base currency next to the original.
 */
#[Fillable(['campaign_id', 'date', 'zone', 'amount', 'currency'])]
class Cost extends Model
{
    protected static function booted(): void
    {
        static::saving(function (Cost $cost): void {
            if ($cost->zone === '') {
                $cost->zone = null;
            }

            $cost->convertToBase();
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
            'date' => 'immutable_date',
            'amount' => 'decimal:4',
            'amount_base' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Fills amount_base by the rate on the cost date; both stay null while the rate is missing.
     */
    public function convertToBase(?CurrencyConverter $converter = null): void
    {
        $converter ??= new CurrencyConverter;
        $baseCurrency = Setting::current()->base_currency;
        $amountBase = $converter->toBase($this->amount, $this->currency, $this->date, $baseCurrency);
        $this->amount_base = $amountBase;
        $this->base_currency = $amountBase === null ? null : $baseCurrency;
    }
}
