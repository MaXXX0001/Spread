<?php

namespace App\Money;

use App\Models\Cost;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps one campaign day either as one whole-campaign cost or as zone costs: both at once would double the day.
 */
final class CostRules
{
    public const string ZONES_EXIST = 'The campaign already has zone costs for this date; remove them or enter the cost by zone.';

    public const string CAMPAIGN_COST_EXISTS = 'The campaign already has a whole-campaign cost for this date; remove it or enter this cost without a zone.';

    public const string DUPLICATE = 'A cost for this campaign, date and zone already exists.';

    /**
     * @return ?string The reason the cost cannot be stored next to the existing ones, or null.
     */
    public static function violation(int $campaignId, string $date, ?string $zone, ?int $ignoreId = null): ?string
    {
        $sameDay = Cost::query()
            ->where('campaign_id', '=', $campaignId)
            ->where('date', '=', $date)
            ->when($ignoreId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId));
        $isDuplicate = (clone $sameDay)
            ->when(
                $zone === null,
                fn (Builder $query): Builder => $query->whereNull('zone'),
                fn (Builder $query): Builder => $query->where('zone', '=', $zone),
            )
            ->exists();

        if ($isDuplicate) {
            return self::DUPLICATE;
        }

        if ($zone === null) {
            $hasZoneCosts = (clone $sameDay)->whereNotNull('zone')->exists();

            return $hasZoneCosts ? self::ZONES_EXIST : null;
        }

        $hasCampaignCost = (clone $sameDay)->whereNull('zone')->exists();

        return $hasCampaignCost ? self::CAMPAIGN_COST_EXISTS : null;
    }
}
