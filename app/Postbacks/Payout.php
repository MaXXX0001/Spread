<?php

namespace App\Postbacks;

final class Payout
{
    /**
     * `12.5000` + `USD` => `12.5 USD`: trailing zeros of the stored 4-digit scale are noise for the admin.
     */
    public static function format(?string $payout, ?string $currency): ?string
    {
        if ($payout === null) {
            return null;
        }

        $withoutZeros = str_contains($payout, '.') ? rtrim($payout, '0') : $payout;
        $amount = rtrim($withoutZeros, '.');

        return $currency === null ? $amount : "{$amount} {$currency}";
    }
}
