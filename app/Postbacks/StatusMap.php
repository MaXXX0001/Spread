<?php

namespace App\Postbacks;

/**
 * A CPA network's status mapping: the network's own status value => our status value.
 */
final class StatusMap
{
    /**
     * Values are compared without case and surrounding spaces: `Sale `, `sale` and `SALE` are one value.
     */
    public static function normalize(string $networkValue): string
    {
        $trimmed = trim($networkValue);

        return mb_strtolower($trimmed);
    }
}
