<?php

use App\Http\Controllers\Mobile\AuthController;
use App\Http\Controllers\Mobile\EmergencyContactsController;
use App\Http\Controllers\Mobile\EmergencyController;
use App\Http\Controllers\Mobile\PolizasController;
use App\Http\Controllers\Mobile\SharedRisksController;
use App\Http\Controllers\Mobile\SiniestroController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas Mobile — prefijo /api/mobile/v1/
|--------------------------------------------------------------------------
|
| Consumidas por la app Flutter (MANGO). El prefijo y el middleware `api`
| se aplican desde bootstrap/app.php (withRouting → then).
|
| El guard es `mobile` (Sanctum sobre MobileAccount).
|
*/

// Actualización de posición del tracking (Estado 2). SIN auth:mobile — la
// invoca el foreground service del device en un isolate sin Sanctum. La
// autorización es el `update_secret` (decisión de seguridad C). Throttle
// generoso: un device legítimo postea ~1 vez cada 2 min; el límite solo
// frena ráfagas, sin estorbar reintentos legítimos en una emergencia.
Route::patch('/emergencia/tracking/{token}/posicion', [EmergencyController::class, 'updatePosition'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:30,1');

Route::prefix('auth')->group(function () {
    // Intercambia el Firebase ID Token por un Sanctum token. Sin auth previa, y por eso
    // con throttle: era la unica ruta publica de mobile sin limite. Un login legitimo
    // gasta uno o dos intentos; el margen cubre reintentos de red del device.
    Route::post('/session', [AuthController::class, 'session'])
        ->middleware('throttle:10,1'); // opcion-de-configuracion: tope de logins por minuto

    Route::middleware('auth:mobile')->group(function () {
        // Cierra la sesión del dispositivo actual.
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:mobile')->group(function () {
    // Pólizas — bloque PAS + propias + riesgos compartidos (Home) y detalle.
    Route::get('/polizas', [PolizasController::class, 'index']);
    Route::get('/polizas/{id}', [PolizasController::class, 'show'])->whereNumber('id');
    // Documentos oficiales persistidos en R2 (URL firmada). La app no habla con Visred.
    Route::get('/polizas/{id}/documentos', [PolizasController::class, 'documentos'])->whereNumber('id');

    // Siniestro — aviso al PAS (spec v2 §4.2). Rate-limit liviano de
    // respaldo: el lock real de 48hs vive en el cliente.
    Route::post('/siniestro', [SiniestroController::class, 'notify'])
        ->middleware('throttle:5,1');

    // Contactos de emergencia (spec v2 §4.3). Máx 3 por cuenta.
    Route::get('/contactos-emergencia', [EmergencyContactsController::class, 'index']);
    Route::post('/contactos-emergencia', [EmergencyContactsController::class, 'store']);
    Route::put('/contactos-emergencia/{id}', [EmergencyContactsController::class, 'update'])->whereNumber('id');
    Route::delete('/contactos-emergencia/{id}', [EmergencyContactsController::class, 'destroy'])->whereNumber('id');

    // Necesito Ayuda — Estado 1/2 + revocación del tracking (Estado 4).
    // Rate-limit corto: anti doble-envío por retry de red.
    Route::post('/emergencia/notificar', [EmergencyController::class, 'notify'])
        ->middleware('throttle:3,1');
    Route::delete('/emergencia/tracking/{token}', [EmergencyController::class, 'revokeTracking']);

    // Cuenta Compartida (shared_risk) — spec v2 §4.6. Solo titular gestiona.
    Route::get('/shared-risks/{polizaId}', [SharedRisksController::class, 'index'])->whereNumber('polizaId');
    Route::post('/shared-risks/invitar', [SharedRisksController::class, 'invite']);
    Route::delete('/shared-risks/{polizaId}/{conductorId}', [SharedRisksController::class, 'revoke'])
        ->whereNumber('polizaId')->whereNumber('conductorId');
    // Auto-revocación del invitado ("Quitar vehículo") — por risk_id, autoriza
    // el match de email del autenticado. No es titular, no maneja polizaId.
    Route::delete('/shared-risks/mias/{riskId}', [SharedRisksController::class, 'revokeMine'])
        ->whereNumber('riskId');
});
