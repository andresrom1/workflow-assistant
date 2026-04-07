<?php

use App\Http\Controllers\Admin\AgentPromptController;
use App\Http\Controllers\Admin\CheckoutAuditController;
use App\Http\Controllers\Admin\ConversationController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\QuoteController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/privacy', function () {
    return view('privacy');
})->name('privacy');

Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');

Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

// Rutas de Cotizaciones (NUEVO)
Route::resource('quotes', QuoteController::class)->only(['index', 'show']);

// ─── Checkout: URL limpia via token opaco (UUID) ──────────────────────────────
// GET  /checkout/{token}   → muestra el formulario (token en el path)
// POST /checkout/submit    → procesa el envío   (token en el body como campo oculto)

Route::get('/checkout/{token}', [CheckoutController::class, 'show'])
    ->name('checkout.show');

Route::post('/checkout/upload-photo', [CheckoutController::class, 'uploadPhoto'])
    ->name('checkout.upload-photo');

Route::delete('/checkout/photo', [CheckoutController::class, 'deletePhoto'])
    ->name('checkout.delete-photo');

Route::post('/checkout/submit', [CheckoutController::class, 'submit'])
    ->name('checkout.submit');

// Pantalla de confirmación post-checkout
Route::get('/checkout/{quote}/success', [CheckoutController::class, 'success'])
    ->name('checkout.success');

// ─── Admin: Auditoría de checkout (requiere autenticación) ───────────────────
// Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/checkout-sessions', [CheckoutAuditController::class, 'index'])
        ->name('checkout-sessions.index');

    Route::get('/checkout-sessions/{checkoutSession}', [CheckoutAuditController::class, 'show'])
        ->name('checkout-sessions.show');

    Route::post('/checkout-sessions/{checkoutSession}/mark-processed', [CheckoutAuditController::class, 'markProcessed'])
        ->name('checkout-sessions.mark-processed');

    Route::post('/checkout-sessions/{checkoutSession}/clear-card-data', [CheckoutAuditController::class, 'clearCardData'])
        ->name('checkout-sessions.clear-card-data');

    Route::get('/settings', [SettingsController::class, 'index'])
        ->name('settings.index');
    Route::post('/settings/{group}', [SettingsController::class, 'updateGroup'])
        ->name('settings.update-group');

    // Conversaciones (dev tool — reset de estado)
    Route::get('/conversations', [ConversationController::class, 'index'])
        ->name('conversations.index');
    Route::post('/conversations/{conversation}/reset', [ConversationController::class, 'reset'])
        ->name('conversations.reset');

    // Gestión de prompts de agentes IA
    Route::get('/agent-prompts', [AgentPromptController::class, 'index'])
        ->name('agent-prompts.index');
    Route::get('/agent-prompts/{agentKey}', [AgentPromptController::class, 'show'])
        ->name('agent-prompts.show');
    Route::post('/agent-prompts/{agentKey}', [AgentPromptController::class, 'store'])
        ->name('agent-prompts.store');
    Route::post('/agent-prompts/{agentPrompt}/activate', [AgentPromptController::class, 'activate'])
        ->name('agent-prompts.activate');
});
