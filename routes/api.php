<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Endpoint de prueba (sin autenticación por ahora)
Route::post('/tools/test', function (Request $request) {
    Log::info('Tool call recibido:', $request->all());
    
    return response()->json([
        'success' => true,
        'message' => '¡Hola desde el backend!',
        'timestamp' => now()->toIso8601String(),
        'received_data' => $request->all()
    ]);
});
