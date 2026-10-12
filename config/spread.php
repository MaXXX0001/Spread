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

];
