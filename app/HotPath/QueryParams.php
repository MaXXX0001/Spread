<?php

namespace App\HotPath;

/**
 * Parses the raw query string by the rules of docs/contract.md: names are kept as is
 * (PHP would turn "a.b" into "a_b" and "sub[1]" into an array), the last value wins.
 */
final class QueryParams
{
    private const int MAX_PARAMS = 50;

    private const int NAME_LIMIT = 64;

    private const int VALUE_LIMIT = 1024;

    /**
     * @return array<string, string>
     */
    public static function parse(string $queryString): array
    {
        $params = [];

        if ($queryString === '') {
            return $params;
        }

        foreach (explode('&', $queryString) as $pair) {
            $parts = explode('=', $pair, 2);
            $rawName = urldecode($parts[0]);
            $name = Utf8::truncate($rawName, self::NAME_LIMIT);

            if ($name === '') {
                continue;
            }

            $isNew = ! array_key_exists($name, $params);

            if (
                $isNew
                && count($params) >= self::MAX_PARAMS
            ) {
                continue;
            }

            $rawValue = urldecode($parts[1] ?? '');
            $params[$name] = Utf8::truncate($rawValue, self::VALUE_LIMIT);
        }

        return $params;
    }
}
