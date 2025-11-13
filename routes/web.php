<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Endpoint de prueba (sin autenticación por ahora)
Route::post('api/tools/test', function (Request $request) {
    Log::info('Tool call recibido:', $request->all());
    
    return response()->json([
        'success' => true,
        'message' => '¡Hola desde el backend!',
        'timestamp' => now()->toIso8601String(),
        'received_data' => $request->all()
    ]);
});