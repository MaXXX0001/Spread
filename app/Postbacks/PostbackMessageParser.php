<?php

namespace App\Postbacks;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use stdClass;

/**
 * Validates a `spread:postbacks` entry by the postback message format of docs/contract.md (v1).
 */
final class PostbackMessageParser
{
    private const int SUPPORTED_VERSION = 1;

    private const string POSTBACK_ID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    private const string TS_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    /**
     * @param  array<string, string>  $fields  Stream entry fields.
     * @return array{postback_id: string, received_at: string, key: string, ip: ?string, params: string}
     *
     * @throws InvalidPostbackMessage
     */
    public static function parse(array $fields): array
    {
        if (! array_key_exists('payload', $fields)) {
            throw new InvalidPostbackMessage('missing payload field');
        }

        try {
            // Objects as stdClass to tell an empty `params` object from a list.
            $message = json_decode($fields['payload'], false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidPostbackMessage("invalid JSON: {$exception->getMessage()}");
        }

        if (! $message instanceof stdClass) {
            throw new InvalidPostbackMessage('invalid JSON: payload is not an object');
        }

        $version = self::field($message, 'v');

        if ($version !== self::SUPPORTED_VERSION) {
            $encodedVersion = json_encode($version);

            throw new InvalidPostbackMessage("unsupported version: {$encodedVersion}");
        }

        $postbackId = self::field($message, 'postback_id');

        if (
            ! is_string($postbackId)
            || preg_match(self::POSTBACK_ID_PATTERN, $postbackId) !== 1
        ) {
            throw new InvalidPostbackMessage('invalid field: postback_id');
        }

        $receivedAt = self::receivedAt($message);
        $key = self::stringField($message, 'key');
        $ip = self::stringField($message, 'ip');
        $params = self::params($message);
        $validIp = filter_var($ip, FILTER_VALIDATE_IP) === false ? null : $ip;

        return [
            'postback_id' => $postbackId,
            'received_at' => $receivedAt,
            'key' => $key,
            'ip' => $validIp,
            'params' => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    private static function field(stdClass $message, string $name): mixed
    {
        if (! property_exists($message, $name)) {
            throw new InvalidPostbackMessage("missing field: {$name}");
        }

        return $message->{$name};
    }

    private static function stringField(stdClass $message, string $name): string
    {
        $value = self::field($message, $name);

        if (! is_string($value)) {
            throw new InvalidPostbackMessage("invalid field: {$name}");
        }

        return $value;
    }

    /**
     * @return string UTC timestamp with milliseconds in the database format.
     */
    private static function receivedAt(stdClass $message): string
    {
        $ts = self::field($message, 'ts');
        $utc = new DateTimeZone('UTC');
        $parsed = is_string($ts) ? DateTimeImmutable::createFromFormat(self::TS_FORMAT, $ts, $utc) : false;

        // createFromFormat() rolls over out-of-range parts (month 13), so require an exact round trip.
        if (
            $parsed === false
            || $parsed->format(self::TS_FORMAT) !== $ts
        ) {
            throw new InvalidPostbackMessage('invalid field: ts');
        }

        return $parsed->format('Y-m-d H:i:s.v');
    }

    private static function params(stdClass $message): stdClass
    {
        $params = self::field($message, 'params');

        if (! $params instanceof stdClass) {
            throw new InvalidPostbackMessage('invalid field: params');
        }

        foreach (get_object_vars($params) as $value) {
            if (! is_string($value)) {
                throw new InvalidPostbackMessage('invalid field: params');
            }
        }

        return $params;
    }
}
