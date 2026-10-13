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

// Conversion statuses change after the click, so recent days are recalculated often and a month nightly.
Schedule::command('spread:reports:aggregate')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('spread:reports:aggregate --days=30')
    ->dailyAt('03:30')
    ->timezone('Europe/Kyiv')
    ->withoutOverlapping();

// Sent from the command, not a queued job: an alert about stopped workers must not depend on the queue worker.
Schedule::command('spread:alerts:check')
    ->everyMinute()
    ->withoutOverlapping();

// Runs every minute and sends once a day: the time is an admin setting read at run time.
Schedule::command('spread:alerts:daily')
    ->everyMinute()
    ->withoutOverlapping();
