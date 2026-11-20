<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console routes
|--------------------------------------------------------------------------
| schedule:run is driven by a single per-user crontab entry on the server.
*/

// Polling fallback safety net — re-dispatch in-flight generations.
Schedule::command('promptedit:sync-stuck')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();

// Daily provider reconciliation snapshot.
Schedule::command('promptedit:reconcile-usage --days=30')
    ->dailyAt('03:07')
    ->withoutOverlapping(10);
