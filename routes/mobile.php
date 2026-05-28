<?php

use App\Http\Controllers\Mobile\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas Mobile — prefijo /api/mobile/v1/
|--------------------------------------------------------------------------
|
| Consumidas por la app Flutter (MANGO). El prefijo y el middleware `api`
| se aplican desde bootstrap/app.php (withRouting → then).
|
*/

Route::prefix('auth')->group(function () {
    // Intercambia el Firebase ID Token por un Sanctum token. Sin auth previa.
    Route::post('/session', [AuthController::class, 'session']);

    Route::middleware('auth:sanctum')->group(function () {
        // Vinculación de identidad (declara DNI → matchea tomador).
        Route::post('/link', [AuthController::class, 'link']);
        // Cierra la sesión del dispositivo actual.
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
