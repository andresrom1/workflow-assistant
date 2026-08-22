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

// Seguimiento de conversaciones estancadas: un nudge por conversación, lo más
// cerca posible del cierre de la ventana de 24h de WhatsApp, solo en horario
// comercial (08–20, hora Argentina).
Schedule::command('conversations:follow-up-stalled')
    ->hourly()
    ->timezone('America/Argentina/Buenos_Aires')
    ->between('8:00', '20:00')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| El worker de `background`, bajo demanda
|--------------------------------------------------------------------------
|
| Emisión de póliza, facturación, extracción de PDF por LLM, análisis semántico y
| limpieza: todo lento, poco frecuente, y sin nadie esperando del otro lado. No justifica
| un proceso PHP residente de ~90 MB durmiendo 28.800 veces por día.
|
| `--stop-when-empty` hace que el proceso TERMINE cuando la cola se vacía. En un minuto
| sin trabajo —el 99,9 % de los minutos— arranca, hace un poll, ve la tabla vacía y muere:
| ~0,3 s de CPU y cero RAM residente. En un minuto con trabajo saca los jobs y recién ahí
| termina.
|
| `--max-time=55` corta ENTRE jobs, nunca mata uno en curso: una extracción de PDF de 300 s
| termina igual. `withoutOverlapping(10)` evita que el tick del minuto siguiente levante un
| segundo proceso sobre la misma cola, con un mutex que expira holgadamente por encima del
| job más largo. `runInBackground()` evita que un job largo bloquee las otras tareas del
| mismo tick del scheduler.
|
| `documents` y `semantic-analysis` están en la lista sólo para drenar lo que haya quedado
| en vuelo con los nombres viejos; los jobs ya se despachan a `background`.
|
| Para volver a un worker residente: borrar esta entrada y agregar un
| `[program:worker-background]` con `--sleep=20` en .docker/start.sh.
|
*/
Schedule::command('queue:work database_long --queue=background,documents,semantic-analysis --stop-when-empty --max-time=55 --tries=3 --timeout=300')
    ->everyMinute()
    ->runInBackground()
    ->withoutOverlapping(10);
