<?php

namespace App\Postbacks;

/**
 * The admin's IP whitelist of a CPA network: single IPv4/IPv6 addresses or CIDR ranges, one per line.
 */
final class IpList
{
    /**
     * @return list<string>
     */
    public static function parse(string $text): array
    {
        $lines = preg_split('/\R/', $text);
        $entries = array_map(trim(...), $lines);
        $nonEmpty = array_filter($entries, fn (string $entry): bool => $entry !== '');

        return array_values($nonEmpty);
    }

    public static function isValidEntry(string $entry): bool
    {
        $parts = explode('/', $entry, 2);
        $ip = $parts[0];
        $prefix = $parts[1] ?? null;
        $isIpv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (
            ! $isIpv4
            && ! $isIpv6
        ) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        $maxPrefix = $isIpv4 ? 32 : 128;
        $isNumber = preg_match('/^\d{1,3}$/', $prefix) === 1;

        return $isNumber && (int) $prefix <= $maxPrefix;
    }
}
