<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// NBU publishes the next day's rates during the working day; a 09:00 run also picks up today's.
Schedule::command('spread:rates:update')
    ->dailyAt('09:00')
    ->timezone('Europe/Kyiv');
