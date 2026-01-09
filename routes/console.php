<?php


use Illuminate\Support\Facades\Schedule;


/*
|--------------------------------------------------------------------------
| Scheduled Commands
|--------------------------------------------------------------------------
|
| Here you may define all of your scheduled commands. Commands are run
| in a single, sequential process to avoid race conditions.
|
*/

// Sync vehicles from Samsara API every 5 minutes
Schedule::command('samsara:sync-vehicles')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/vehicle-sync.log'));

// Sync drivers from Samsara API every 5 minutes
Schedule::command('samsara:sync-drivers')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/driver-sync.log'));

// Sync tags from Samsara API once daily at 3:00 AM
Schedule::command('samsara:sync-tags')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/tag-sync.log'));

// Sync vehicle stats (GPS, engine state, odometer) every 30 seconds
Schedule::command('samsara:sync-vehicle-stats')
    ->everyThirtySeconds()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/vehicle-stats-sync.log'));
