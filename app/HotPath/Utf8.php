<?php

namespace App\HotPath;

final class Utf8
{
    /**
     * Replaces invalid UTF-8 sequences with U+FFFD, then keeps at most $limit Unicode characters.
     */
    public static function truncate(string $value, int $limit): string
    {
        // mbstring substitutes '?' by default; the contract wants U+FFFD, as Go's encoding/json does.
        $previousSubstitute = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $scrubbed = mb_scrub($value, 'UTF-8');
        mb_substitute_character($previousSubstitute);

        return mb_substr($scrubbed, 0, $limit, 'UTF-8');
    }
}
