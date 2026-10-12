<?php

namespace App\Models;

use App\Money\CurrencyConverter;
use App\Postbacks\ConversionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversion extends Model
{
    /**
     * Keeps milliseconds: the stale-postback check compares last_postback_at with postback times.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected static function booted(): void
    {
        static::saving(function (Conversion $conversion): void {
            $conversion->convertPayoutToBase();
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
            'status' => ConversionStatus::class,
            'payout' => 'decimal:4',
            'payout_base' => 'decimal:4',
            'last_postback_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * The day the payout is converted on: the last status change, as a day in the report timezone.
     */
    public function rateDate(string $reportTimezone): CarbonImmutable
    {
        $statusChangedAt = CarbonImmutable::instance($this->status_changed_at);
        $localTime = $statusChangedAt->setTimezone($reportTimezone);

        return $localTime->startOfDay();
    }

    /**
     * Fills payout_base; a payout without a currency is taken in the network's default currency.
     */
    public function convertPayoutToBase(?CurrencyConverter $converter = null): void
    {
        if ($this->payout === null) {
            $this->payout_base = null;
            $this->base_currency = null;

            return;
        }

        $converter ??= new CurrencyConverter;
        $setting = Setting::current();
        $currency = $this->currency ?? $this->cpaNetwork->default_currency;
        $rateDate = $this->rateDate($setting->report_timezone);
        $payoutBase = $converter->toBase($this->payout, $currency, $rateDate, $setting->base_currency);
        $this->payout_base = $payoutBase;
        $this->base_currency = $payoutBase === null ? null : $setting->base_currency;
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
