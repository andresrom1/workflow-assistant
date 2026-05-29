<?php

use App\Http\Controllers\Mobile\AuthController;
use App\Http\Controllers\Mobile\EmergencyContactsController;
use App\Http\Controllers\Mobile\PolizasController;
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

Route::prefix('auth')->group(function () {
    // Intercambia el Firebase ID Token por un Sanctum token. Sin auth previa.
    Route::post('/session', [AuthController::class, 'session']);

    Route::middleware('auth:mobile')->group(function () {
        // Vinculación de identidad (declara DNI → matchea tomador).
        // Rate limit: 5 intentos cada 15 min por MobileAccount autenticada,
        // para frenar brute-force de DNI sobre una cuenta Google comprometida.
        Route::post('/link', [AuthController::class, 'link'])
            ->middleware('throttle:mobile-link');

        // Cierra la sesión del dispositivo actual.
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:mobile')->group(function () {
    // Pólizas — bloque PAS + propias + riesgos compartidos (Home) y detalle.
    Route::get('/polizas', [PolizasController::class, 'index']);
    Route::get('/polizas/{id}', [PolizasController::class, 'show'])->whereNumber('id');

    // Siniestro — aviso al PAS (spec v2 §4.2). Rate-limit liviano de
    // respaldo: el lock real de 48hs vive en el cliente.
    Route::post('/siniestro', [SiniestroController::class, 'notify'])
        ->middleware('throttle:5,1');

    // Contactos de emergencia (spec v2 §4.3). Máx 3 por cuenta.
    Route::get('/contactos-emergencia', [EmergencyContactsController::class, 'index']);
    Route::post('/contactos-emergencia', [EmergencyContactsController::class, 'store']);
    Route::put('/contactos-emergencia/{id}', [EmergencyContactsController::class, 'update'])->whereNumber('id');
    Route::delete('/contactos-emergencia/{id}', [EmergencyContactsController::class, 'destroy'])->whereNumber('id');
});
