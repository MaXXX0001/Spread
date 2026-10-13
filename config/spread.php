<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tracker URL
    |--------------------------------------------------------------------------
    |
    | Base URL of the click redirect. Campaign tracking links are built as
    | "{tracker_url}/c/{alias}?param=macro...".
    |
    */

    'tracker_url' => env('SPREAD_TRACKER_URL', env('APP_URL')),

    /*
    |--------------------------------------------------------------------------
    | Fallback URL
    |--------------------------------------------------------------------------
    |
    | Where the redirect sends visitors of unknown or disabled campaigns and
    | whenever Redis is unavailable. Published in the config snapshot meta.
    |
    */

    'fallback_url' => env('SPREAD_FALLBACK_URL'),

    /*
    |--------------------------------------------------------------------------
    | GeoIP database
    |--------------------------------------------------------------------------
    |
    | DB-IP country lite database in MaxMind mmdb format, downloaded by
    | "spread:geoip:update" and read by the click worker.
    |
    */

    'geoip_path' => storage_path('app/geoip/dbip-country-lite.mmdb'),

    /*
    |--------------------------------------------------------------------------
    | Database backups
    |--------------------------------------------------------------------------
    |
    | "spread:backup" keeps the newest "keep" dumps in "path" and, when the
    | "backups" disk has a bucket, on that disk too. pg_dump and pg_restore
    | must be at least as new as the PostgreSQL server.
    |
    */

    'backup' => [
        'path' => env('SPREAD_BACKUP_PATH', storage_path('app/backups')),
        'keep' => (int) env('SPREAD_BACKUP_KEEP', 14),
        'pg_dump' => env('SPREAD_PG_DUMP', 'pg_dump'),
        'pg_restore' => env('SPREAD_PG_RESTORE', 'pg_restore'),
    ],

];
