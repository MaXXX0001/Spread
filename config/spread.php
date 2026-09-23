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

];
