<?php

namespace Tests\Support;

/**
 * Builds a minimal IPv4 MaxMind DB (https://maxmind.github.io/MaxMind-DB/) with two country records:
 * addresses 0.0.0.0/1 get $lowerHalf and 128.0.0.0/1 get $upperHalf.
 */
final class CountryMmdb
{
    private const int NODE_COUNT = 1;

    private const int DATA_SECTION_SEPARATOR_SIZE = 16;

    public static function build(string $lowerHalf, string $upperHalf): string
    {
        $lowerRecord = self::countryRecord($lowerHalf);
        $upperRecord = self::countryRecord($upperHalf);
        $dataSection = $lowerRecord.$upperRecord;

        // A record value above the node count points into the data section (offset = value - node count - 16).
        $lowerPointer = self::NODE_COUNT + self::DATA_SECTION_SEPARATOR_SIZE;
        $upperPointer = $lowerPointer + strlen($lowerRecord);
        $searchTree = self::uint24($lowerPointer).self::uint24($upperPointer);

        $separator = str_repeat("\0", self::DATA_SECTION_SEPARATOR_SIZE);
        $metadata = self::map([
            'binary_format_major_version' => self::uint(5, 2),
            'binary_format_minor_version' => self::uint(5, 0),
            'build_epoch' => "\x00\x02",
            'database_type' => self::string('Test-Country'),
            'description' => self::map([]),
            'ip_version' => self::uint(5, 4),
            'languages' => "\x00\x04",
            'node_count' => self::uint(6, self::NODE_COUNT),
            'record_size' => self::uint(5, 24),
        ]);

        return $searchTree.$separator.$dataSection."\xAB\xCD\xEFMaxMind.com".$metadata;
    }

    private static function countryRecord(string $isoCode): string
    {
        $country = self::map(['iso_code' => self::string($isoCode)]);

        return self::map(['country' => $country]);
    }

    /**
     * @param  array<string, string>  $entries  Key => encoded value.
     */
    private static function map(array $entries): string
    {
        $encoded = chr((7 << 5) | count($entries));

        foreach ($entries as $key => $value) {
            $encoded .= self::string($key).$value;
        }

        return $encoded;
    }

    private static function string(string $value): string
    {
        return chr((2 << 5) | strlen($value)).$value;
    }

    private static function uint(int $type, int $value): string
    {
        return chr(($type << 5) | 1).chr($value);
    }

    private static function uint24(int $value): string
    {
        return substr(pack('N', $value), 1);
    }
}
