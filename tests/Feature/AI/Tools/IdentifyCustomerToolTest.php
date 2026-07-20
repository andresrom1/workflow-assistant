<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Tools\IdentifyCustomerTool;
use App\Models\Conversation;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

it('marca customer_identified cuando el customer resultante ya tiene DNI', function () {
    $conversation = Conversation::factory()->create(['metadata' => ['ai_state' => ['customer_identified' => false]]]);
    $customer = Customer::factory()->create(); // dni por default (factory)

    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $adapter->shouldReceive('identifyCustomer')
        ->once()
        ->andReturnUsing(function () use ($conversation, $customer) {
            $conversation->update(['customer_id' => $customer->id]);

            return ['success' => true, 'tool_output' => 'Cliente identificado correctamente.'];
        });

    $tool = new IdentifyCustomerTool($adapter, $conversation);
    $tool->handle(new Request(['identifier_type' => 'email', 'identifier_value' => 'x@example.com']));

    expect($conversation->fresh()->aiState()['customer_identified'])->toBeTrue();
});

it('NO marca customer_identified cuando el customer resultante todavía no tiene DNI', function () {
    $conversation = Conversation::factory()->create(['metadata' => ['ai_state' => ['customer_identified' => false]]]);
    $customer = Customer::factory()->create(['dni' => null]);

    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $adapter->shouldReceive('identifyCustomer')
        ->once()
        ->andReturnUsing(function () use ($conversation, $customer) {
            $conversation->update(['customer_id' => $customer->id]);

            return ['success' => true, 'tool_output' => 'Cliente identificado correctamente.'];
        });

    $tool = new IdentifyCustomerTool($adapter, $conversation);
    $tool->handle(new Request(['identifier_type' => 'email', 'identifier_value' => 'x@example.com']));

    // El identify por email fue exitoso (customer vinculado), pero el paso de
    // identificación queda abierto hasta que llegue el DNI (o DeclineDniTool).
    expect($conversation->fresh()->aiState()['customer_identified'])->toBeFalse();
});

it('INTEGRACIÓN (sin mock): dar el DNI en el chat lo persiste y cierra el paso', function () {
    // Cliente ya vinculado por teléfono, sin DNI — el estado más común al llegar
    // al CustomerIdentifierAgent tras el gate nuevo de tryAutoIdentifyByPhone.
    $customer = Customer::factory()->create(['dni' => null, 'phone' => '+5493511234567']);
    $conversation = Conversation::factory()->create([
        'customer_id' => $customer->id,
        'external_conversation_id' => '5493511234567',
        'metadata' => ['ai_state' => ['customer_identified' => false]],
    ]);

    // Adapter REAL desde el container: ejercita resolveForConversation →
    // CustomerConsolidationService → Customer::saving (normalización).
    $tool = new IdentifyCustomerTool(app(WhatsAppAdapter::class), $conversation);
    $result = json_decode($tool->handle(new Request([
        'identifier_type' => 'dni',
        'identifier_value' => '30.123.727', // con puntos, como lo escribiría un cliente
    ])), true);

    expect($result['success'])->toBeTrue()
        ->and($customer->fresh()->dni)->toBe('30123727')
        ->and($conversation->fresh()->aiState()['customer_identified'])->toBeTrue();
});

it('INTEGRACIÓN (sin mock): identificar por email NO cierra el paso (falta el DNI)', function () {
    $customer = Customer::factory()->create(['dni' => null, 'email' => null, 'phone' => '+5493511234568']);
    $conversation = Conversation::factory()->create([
        'customer_id' => $customer->id,
        'external_conversation_id' => '5493511234568',
        'metadata' => ['ai_state' => ['customer_identified' => false]],
    ]);

    $tool = new IdentifyCustomerTool(app(WhatsAppAdapter::class), $conversation);
    $result = json_decode($tool->handle(new Request([
        'identifier_type' => 'email',
        'identifier_value' => 'nuevo@example.com',
    ])), true);

    expect($result['success'])->toBeTrue()
        ->and($customer->fresh()->email)->toBe('nuevo@example.com')
        ->and($conversation->fresh()->aiState()['customer_identified'])->toBeFalse();
});

it('no toca ai_state cuando el adapter reporta fallo', function () {
    $conversation = Conversation::factory()->create(['metadata' => ['ai_state' => ['customer_identified' => false]]]);

    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $adapter->shouldReceive('identifyCustomer')
        ->once()
        ->andReturn(['success' => false, 'error' => 'DNI inválido', 'error_code' => 'validation_error']);

    $tool = new IdentifyCustomerTool($adapter, $conversation);
    $result = json_decode($tool->handle(new Request(['identifier_type' => 'dni', 'identifier_value' => 'x'])), true);

    expect($result['success'])->toBeFalse()
        ->and($conversation->fresh()->aiState()['customer_identified'])->toBeFalse();
});
