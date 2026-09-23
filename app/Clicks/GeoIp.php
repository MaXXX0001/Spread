<?php

namespace App\Clicks;

use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;

/**
 * Country of a click by its IP from the local DB-IP country lite database (design D13).
 */
final class GeoIp
{
    // DB-IP marks addresses it cannot place (private, reserved, unknown) with this code.
    private const string UNKNOWN_COUNTRY = 'ZZ';

    private readonly ?Reader $reader;

    /**
     * The file is opened once here, so a worker picks up an updated database only after a restart.
     */
    public function __construct(?string $path = null)
    {
        $path ??= config('spread.geoip_path');

        if (! is_file($path)) {
            Log::warning('GeoIP database not found; clicks are stored without a country.', ['path' => $path]);
            $this->reader = null;

            return;
        }

        $this->reader = new Reader($path);
    }

    public function country(?string $ip): ?string
    {
        if (
            $ip === null
            || $this->reader === null
        ) {
            return null;
        }

        $record = $this->reader->get($ip);
        $country = $record['country']['iso_code'] ?? null;

        return $country === self::UNKNOWN_COUNTRY ? null : $country;
    }
}
