<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\InsuranceOrchestrator;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Invoca el método privado tryAutoIdentifyByPhone vía reflexión (documentado
 * en el CLAUDE.md como patrón aceptado para smoke-tests de métodos privados).
 */
function invokeTryAutoIdentifyByPhone(InsuranceOrchestrator $orchestrator, Conversation $conversation): void
{
    $method = (new ReflectionClass($orchestrator))->getMethod('tryAutoIdentifyByPhone');
    $method->setAccessible(true);
    $method->invoke($orchestrator, $conversation);
}

it('backfills customer name from the webhook sender_name after auto phone identification', function () {
    $waId = '5491112345678';

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $waId,
        'customer_id' => null,
        'metadata' => ['ai_state' => ['customer_identified' => false]],
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola',
        'external_message_id' => 'wamid.backfill001',
        'sender_name' => 'Juan Perez',
        'sender_phone' => $waId,
    ]);

    $customer = Customer::factory()->create(['name' => null, 'phone' => $waId]);

    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $adapter->shouldReceive('identifyCustomer')
        ->once()
        ->andReturnUsing(function () use ($conversation, $customer) {
            $conversation->update(['customer_id' => $customer->id]);

            return ['success' => true];
        });

    $orchestrator = new InsuranceOrchestrator($adapter);

    invokeTryAutoIdentifyByPhone($orchestrator, $conversation);

    expect($customer->fresh()->name)->toBe('Juan Perez')
        ->and($conversation->fresh()->aiState()['customer_identified'])->toBeTrue();
});

it('does not overwrite an existing customer name', function () {
    $waId = '5491112345679';

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $waId,
        'customer_id' => null,
        'metadata' => ['ai_state' => ['customer_identified' => false]],
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola',
        'external_message_id' => 'wamid.backfill002',
        'sender_name' => 'Nombre Del Webhook',
        'sender_phone' => $waId,
    ]);

    $customer = Customer::factory()->create(['name' => 'Nombre Real', 'phone' => $waId]);

    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $adapter->shouldReceive('identifyCustomer')
        ->once()
        ->andReturnUsing(function () use ($conversation, $customer) {
            $conversation->update(['customer_id' => $customer->id]);

            return ['success' => true];
        });

    $orchestrator = new InsuranceOrchestrator($adapter);

    invokeTryAutoIdentifyByPhone($orchestrator, $conversation);

    expect($customer->fresh()->name)->toBe('Nombre Real');
});

it('skips backfill when there is no inbound message with sender_name yet', function () {
    $waId = '5491112345680';

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $waId,
        'customer_id' => null,
        'metadata' => ['ai_state' => ['customer_identified' => false]],
    ]);

    $customer = Customer::factory()->create(['name' => null, 'phone' => $waId]);

    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $adapter->shouldReceive('identifyCustomer')
        ->once()
        ->andReturnUsing(function () use ($conversation, $customer) {
            $conversation->update(['customer_id' => $customer->id]);

            return ['success' => true];
        });

    $orchestrator = new InsuranceOrchestrator($adapter);

    invokeTryAutoIdentifyByPhone($orchestrator, $conversation);

    expect($customer->fresh()->name)->toBeNull();
});
