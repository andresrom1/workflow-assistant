<?php

use App\Http\Controllers\Admin\AgentExecutionLogAnnotationController;
use App\Http\Controllers\Admin\AgentPromptController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\CheckoutAuditController;
use App\Http\Controllers\Admin\ConversationController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StudioController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ConversationController as CustomerConversationController;
use App\Http\Controllers\CoverageDocumentController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\PolicyDocumentController;
use App\Http\Controllers\PolizaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\TrackingController;
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

    Route::get('/conversations', [CustomerConversationController::class, 'index'])->name('conversations.index');
    Route::get('/conversations/create', [CustomerConversationController::class, 'create'])->name('conversations.create');
    Route::post('/conversations', [CustomerConversationController::class, 'store'])->name('conversations.store');
    Route::get('/conversations/{customer}', [CustomerConversationController::class, 'show'])->name('conversations.show');
    Route::get('/conversations/{customer}/edit', [CustomerConversationController::class, 'edit'])->name('conversations.edit');
    Route::put('/conversations/{customer}', [CustomerConversationController::class, 'update'])->name('conversations.update');
    Route::delete('/conversations/{customer}', [CustomerConversationController::class, 'destroy'])->name('conversations.destroy');

    // Clientes — registro canónico del cliente (consolida datos de chat + checkout).
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    Route::post('/customers/{customer}/resolve-divergence', [CustomerController::class, 'resolveDivergence'])->name('customers.resolve-divergence');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');

    // Pólizas — alta/edición/baja manual desde el panel (la carga de documentos vive en policy-documents).
    Route::resource('polizas', PolizaController::class)
        ->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);

    Route::resource('quotes', QuoteController::class)->only(['index', 'show']);

    Route::resource('coverage-documents', CoverageDocumentController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    // Documentos de póliza — carga manual post-emisión (renovaciones/endosos/correcciones).
    // El admin busca una póliza (index) y entra a su gestor de documentos (show).
    Route::get('/policy-documents', [PolicyDocumentController::class, 'index'])
        ->name('policy-documents.index');
    Route::get('/policy-documents/{poliza}', [PolicyDocumentController::class, 'show'])
        ->whereNumber('poliza')->name('policy-documents.show');
    Route::post('/policy-documents/{poliza}/documents', [PolicyDocumentController::class, 'store'])
        ->whereNumber('poliza')->name('policy-documents.store');
    Route::patch('/policy-documents/documents/{policyDocument}/visibility', [PolicyDocumentController::class, 'toggleVisibility'])
        ->name('policy-documents.toggle-visibility');
    Route::delete('/policy-documents/documents/{policyDocument}', [PolicyDocumentController::class, 'destroy'])
        ->name('policy-documents.destroy');

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
        Route::post('/conversations/{conversation}/analyze-semantics', [ConversationController::class, 'analyzeSemantics'])
            ->name('conversations.analyze-semantics');

        Route::post('/execution-logs/{log}/annotations', [AgentExecutionLogAnnotationController::class, 'store'])
            ->name('execution-logs.annotations.store');
        Route::delete('/execution-logs/{log}/annotations', [AgentExecutionLogAnnotationController::class, 'destroy'])
            ->name('execution-logs.annotations.destroy');

        Route::get('/agent-prompts', [AgentPromptController::class, 'index'])
            ->name('agent-prompts.index');
        Route::get('/agent-prompts/view/{agentPrompt}', [AgentPromptController::class, 'view'])
            ->name('agent-prompts.view');
        Route::get('/agent-prompts/{agentKey}', [AgentPromptController::class, 'show'])
            ->name('agent-prompts.show');
        Route::post('/agent-prompts/{agentKey}', [AgentPromptController::class, 'store'])
            ->name('agent-prompts.store');
        Route::post('/agent-prompts/{agentPrompt}/activate', [AgentPromptController::class, 'activate'])
            ->name('agent-prompts.activate');

        // Draft flow (Fase 4)
        Route::post('/agent-prompts/{agentKey}/drafts', [AgentPromptController::class, 'createDraft'])
            ->name('agent-prompts.drafts.create');
        Route::put('/agent-prompts/drafts/{agentPrompt}', [AgentPromptController::class, 'updateDraft'])
            ->name('agent-prompts.drafts.update');
        Route::post('/agent-prompts/drafts/{agentPrompt}/promote', [AgentPromptController::class, 'promoteDraft'])
            ->name('agent-prompts.drafts.promote');
        Route::post('/agent-prompts/drafts/{agentPrompt}/take-control', [AgentPromptController::class, 'takeDraftControl'])
            ->name('agent-prompts.drafts.take-control');
        Route::delete('/agent-prompts/drafts/{agentPrompt}', [AgentPromptController::class, 'discardDraft'])
            ->name('agent-prompts.drafts.discard');

        // Analytics — Heatmap de steps (Fase 3)
        Route::get('/analytics/funnel', [AnalyticsController::class, 'funnel'])
            ->name('analytics.funnel');

        // Studio — Reevaluación de turn (Fase 6)
        Route::get('/studio/reevaluate/{log}', [StudioController::class, 'show'])
            ->name('studio.show');
        Route::post('/studio/reevaluate', [StudioController::class, 'reevaluate'])
            ->name('studio.reevaluate');

        // Gestión de usuarios
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});

// Endpoint público para los contactos de emergencia (spec v2 §4.3).
// Sin auth — el token es la autorización.
Route::get('/track/{token}', [TrackingController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+');

require __DIR__.'/auth.php';
