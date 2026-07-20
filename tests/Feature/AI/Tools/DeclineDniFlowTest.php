<?php

use App\AI\Tools\DeclineDniTool;
use App\Models\Conversation;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

it('cierra el paso y registra la negativa SIN pisar el resto de ai_state ni de metadata', function () {
    $customer = Customer::factory()->create(['dni' => null]);
    $conversation = Conversation::factory()->create([
        'customer_id' => $customer->id,
        'metadata' => [
            'ai_state' => ['customer_identified' => false, 'vehicle_identified' => true],
            'algo_no_relacionado' => 'se conserva',
        ],
    ]);

    $tool = new DeclineDniTool($conversation);
    $result = json_decode($tool->handle(new Request(['motivo' => 'prefiere no darlo'])), true);

    $fresh = $conversation->fresh();

    expect($result['success'])->toBeTrue()
        // El flag se prendió...
        ->and($fresh->aiState()['customer_identified'])->toBeTrue()
        // ...sin pisar los otros flags del estado (updateAiState + update de metadata
        // son dos escrituras seguidas sobre la misma columna JSON).
        ->and($fresh->aiState()['vehicle_identified'])->toBeTrue()
        // ...ni el resto de metadata.
        ->and($fresh->metadata['algo_no_relacionado'])->toBe('se conserva')
        // La negativa queda registrada FUERA de ai_state (no agrega flags al funnel).
        ->and($fresh->metadata['dni_declined_at'])->not->toBeNull()
        ->and($fresh->aiState())->not->toHaveKey('dni_declined_at');

    // No inventó ningún DNI.
    expect($customer->fresh()->dni)->toBeNull();
});

it('no cierra el paso cuando la conversación todavía no tiene customer vinculado', function () {
    $conversation = Conversation::factory()->create([
        'customer_id' => null,
        'metadata' => ['ai_state' => ['customer_identified' => false]],
    ]);

    $tool = new DeclineDniTool($conversation);
    $result = json_decode($tool->handle(new Request([])), true);

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe('missing_customer')
        ->and($conversation->fresh()->aiState()['customer_identified'])->toBeFalse()
        ->and($conversation->fresh()->metadata)->not->toHaveKey('dni_declined_at');
});
