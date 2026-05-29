<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('checkout:cleanup-temp-photos')->hourly();

// Feature 4.4 — Poll del feed CAP del SMN → fan-out a FCM topic acp-ar.
// El scheduler dispara cada 5 min; el comando decide si es su turno según la
// cadencia estacional (Oct-Mar cada 12 min / Abr-Sep cada 30 min).
// withoutOverlapping(5) corta solapamientos si una corrida se cuelga contra red.
Schedule::command('smn:poll-acp')->everyFiveMinutes()->withoutOverlapping(5);
