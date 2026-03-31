<?php

use App\Http\Controllers\AI\WhatsAppWebhookController;
use App\Http\Controllers\Api\QuoteWebhookController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\TestingController;
use App\Http\Controllers\ToolsController;
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
        'received_data' => $request->all(),
    ]);
});

Route::post('v1/quotes/', [QuoteController::class, 'store']);

Route::prefix('web-chat/v1/tools')->group(function () {
    Route::post('/identify-customer', [ToolsController::class, 'identifyCustomer']);
    Route::post('/identify-vehicle', [ToolsController::class, 'identifyVehicle']);
    Route::post('/coverage-preference', [ToolsController::class, 'coveragePreference']);
    Route::post('/get-quote', [ToolsController::class, 'getQuote']);
    Route::post('/checkout', [ToolsController::class, 'checkout']);
});

Route::get('quotes/{quote}/raw', [QuoteController::class, 'showRaw']);

// Sin imnplementar aún
Route::post('tools/save-vehicle-data', [ToolsController::class, 'saveVehicleData']);
Route::post('tools/create-pending-quote', [ToolsController::class, 'createPendingQuote']);
Route::post('tools/show-data-form', [ToolsController::class, 'showDataForm']);
Route::post('tools/show-vehicle-photos-form', [ToolsController::class, 'showVehiclePhotosForm']);
Route::post('tools/show-payment-form', [ToolsController::class, 'showPaymentForm']);
Route::post('tools/finalize-policy', [ToolsController::class, 'finalizePolicy']);

// Webhooks para recibir actualizaciones de la app móvil
Route::post('/webhooks/quote-update', [QuoteWebhookController::class, 'handle']);

// Webhooks de WhatsApp Business Cloud API
Route::prefix('webhooks/whatsapp')->group(function () {
    Route::get('/', [WhatsAppWebhookController::class, 'verify']);
    Route::post('/', [WhatsAppWebhookController::class, 'handleIncoming']);
});

Route::prefix('dev')->group(function () {

    // Ejecutar tests
    Route::get('/run-tests', [TestingController::class, 'runTests']);

    // Estado de la BD
    Route::get('/database-status', [TestingController::class, 'databaseStatus']);

    // Limpiar BD
    Route::post('/clean-database', [TestingController::class, 'cleanDatabase']);

    // Info del sistema
    Route::get('/system-info', [TestingController::class, 'systemInfo']);

    // ⚠️ PELIGROSO: Solo habilitar si realmente lo necesitas
    Route::post('/fresh-migrations', [TestingController::class, 'freshMigrations']);
});
