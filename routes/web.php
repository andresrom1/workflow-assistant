<?php

use App\Http\Controllers\Admin\AgentPromptController;
use App\Http\Controllers\Admin\CheckoutAuditController;
use App\Http\Controllers\Admin\ConversationController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CoverageDocumentController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuoteController;
use Illuminate\Support\Facades\Route;

// ─── Público ─────────────────────────────────────────────────────────────────
Route::get('/', function () {
    return view('welcome');
});

Route::get('/privacy', function () {
    return view('privacy');
})->name('privacy');

// ─── Checkout: URL limpia via token opaco (UUID) ──────────────────────────────
// Accesible sin autenticación — el token es la credencial de acceso
Route::get('/checkout/{token}', [CheckoutController::class, 'show'])
    ->name('checkout.show');

Route::post('/checkout/upload-photo', [CheckoutController::class, 'uploadPhoto'])
    ->name('checkout.upload-photo');

Route::delete('/checkout/photo', [CheckoutController::class, 'deletePhoto'])
    ->name('checkout.delete-photo');

Route::post('/checkout/submit', [CheckoutController::class, 'submit'])
    ->name('checkout.submit');

Route::get('/checkout/{quote}/success', [CheckoutController::class, 'success'])
    ->name('checkout.success');

// ─── Autenticado ──────────────────────────────────────────────────────────────
Route::middleware(['auth'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

    Route::resource('quotes', QuoteController::class)->only(['index', 'show']);

    Route::resource('coverage-documents', CoverageDocumentController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    // ─── Solo Admin ───────────────────────────────────────────────────────────
    Route::middleware(['admin'])->prefix('admin')->name('admin.')->group(function () {
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

        Route::get('/conversations', [ConversationController::class, 'index'])
            ->name('conversations.index');
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])
            ->name('conversations.show');
        Route::post('/conversations/{conversation}/reset', [ConversationController::class, 'reset'])
            ->name('conversations.reset');

        Route::get('/agent-prompts', [AgentPromptController::class, 'index'])
            ->name('agent-prompts.index');
        Route::get('/agent-prompts/{agentKey}', [AgentPromptController::class, 'show'])
            ->name('agent-prompts.show');
        Route::post('/agent-prompts/{agentKey}', [AgentPromptController::class, 'store'])
            ->name('agent-prompts.store');
        Route::post('/agent-prompts/{agentPrompt}/activate', [AgentPromptController::class, 'activate'])
            ->name('agent-prompts.activate');

        // Gestión de usuarios
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});

require __DIR__.'/auth.php';
