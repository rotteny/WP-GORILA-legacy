<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Housekeeping de repair tasks — expira dispatched órfãs / que estouraram MAX_ATTEMPTS.
// Roda inline no polling do cockpit também, mas o schedule garante que tasks sem
// polling ativo (ex: cockpit offline) ainda transitem pra estado terminal.
Schedule::command('agent:expire-stale')->everyMinute()->withoutOverlapping();
