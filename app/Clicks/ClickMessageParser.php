<?php

namespace App\Clicks;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use stdClass;

/**
 * Validates a `spread:clicks` entry by the click message format of docs/contract.md (v1).
 */
final class ClickMessageParser
{
    private const int SUPPORTED_VERSION = 1;

    private const string CLICK_ID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    private const string TS_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    private const array STATUSES = ['ok', 'campaign_disabled'];

    /**
     * @param  array<string, string>  $fields  Stream entry fields.
     * @return array{click_id: string, clicked_at: string, status: string, campaign_id: int, offer_id: int, ip: ?string, user_agent: ?string, referer: ?string, params: string}
     *
     * @throws InvalidClickMessage
     */
    public static function parse(array $fields): array
    {
        if (! array_key_exists('payload', $fields)) {
            throw new InvalidClickMessage('missing payload field');
        }

        try {
            // Objects as stdClass to tell an empty `params` object from a list.
            $message = json_decode($fields['payload'], false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidClickMessage("invalid JSON: {$exception->getMessage()}");
        }

        if (! $message instanceof stdClass) {
            throw new InvalidClickMessage('invalid JSON: payload is not an object');
        }

        $version = self::field($message, 'v');

        if ($version !== self::SUPPORTED_VERSION) {
            $encodedVersion = json_encode($version);

            throw new InvalidClickMessage("unsupported version: {$encodedVersion}");
        }

        $clickId = self::field($message, 'click_id');

        if (! is_string($clickId) || preg_match(self::CLICK_ID_PATTERN, $clickId) !== 1) {
            throw new InvalidClickMessage('invalid field: click_id');
        }

        $clickedAt = self::clickedAt($message);
        $status = self::field($message, 'status');

        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidClickMessage('invalid field: status');
        }

        $campaignId = self::intField($message, 'campaign_id');
        $offerId = self::intField($message, 'offer_id');
        $ip = self::field($message, 'ip');

        if (! is_string($ip)) {
            throw new InvalidClickMessage('invalid field: ip');
        }

        $userAgent = self::nullableStringField($message, 'user_agent');
        $referer = self::nullableStringField($message, 'referer');
        $params = self::params($message);
        $validIp = filter_var($ip, FILTER_VALIDATE_IP) === false ? null : $ip;

        return [
            'click_id' => $clickId,
            'clicked_at' => $clickedAt,
            'status' => $status,
            'campaign_id' => $campaignId,
            'offer_id' => $offerId,
            'ip' => $validIp,
            'user_agent' => $userAgent,
            'referer' => $referer,
            'params' => json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    private static function field(stdClass $message, string $name): mixed
    {
        if (! property_exists($message, $name)) {
            throw new InvalidClickMessage("missing field: {$name}");
        }

        return $message->{$name};
    }

    private static function intField(stdClass $message, string $name): int
    {
        $value = self::field($message, $name);

        if (! is_int($value)) {
            throw new InvalidClickMessage("invalid field: {$name}");
        }

        return $value;
    }

    private static function nullableStringField(stdClass $message, string $name): ?string
    {
        $value = self::field($message, $name);

        if ($value !== null && ! is_string($value)) {
            throw new InvalidClickMessage("invalid field: {$name}");
        }

        return $value;
    }

    /**
     * @return string UTC timestamp with milliseconds in the database format.
     */
    private static function clickedAt(stdClass $message): string
    {
        $ts = self::field($message, 'ts');
        $utc = new DateTimeZone('UTC');
        $parsed = is_string($ts) ? DateTimeImmutable::createFromFormat(self::TS_FORMAT, $ts, $utc) : false;

        // createFromFormat() rolls over out-of-range parts (month 13), so require an exact round trip.
        if ($parsed === false || $parsed->format(self::TS_FORMAT) !== $ts) {
            throw new InvalidClickMessage('invalid field: ts');
        }

        return $parsed->format('Y-m-d H:i:s.v');
    }

    private static function params(stdClass $message): stdClass
    {
        $params = self::field($message, 'params');

        if (! $params instanceof stdClass) {
            throw new InvalidClickMessage('invalid field: params');
        }

        foreach (get_object_vars($params) as $value) {
            if (! is_string($value)) {
                throw new InvalidClickMessage('invalid field: params');
            }
        }

        return $params;
    }
}
