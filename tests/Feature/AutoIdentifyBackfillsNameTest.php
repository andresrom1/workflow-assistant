<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\InsuranceOrchestrator;
use App\Jobs\ProcessConversationInbox;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/**
 * La captura de nombre/teléfono se hace en la INGESTA (ProcessWhatsAppMessage), como atributos
 * del Customer; el gate de `customer_identified` (solo si hay DNI) vive en el orquestador. Este
 * archivo cubre ambos loci tras el desacople del viejo `tryAutoIdentifyByPhone`.
 */
function dispatchIngestForCapture(?string $waId, string $bsuid, string $contactName): void
{
    Bus::fake([ProcessConversationInbox::class]);

    ProcessWhatsAppMessage::dispatchSync(
        waId: $waId,
        messageBody: 'Hola',
        messageId: 'wamid.'.uniqid(),
        phoneNumberId: '123456789',
        contactName: $contactName,
        extUserId: $bsuid,
    );
}

/** Invoca el gate privado del orquestador vía reflexión (patrón aceptado en CLAUDE.md). */
function invokeSyncIdentifiedState(Conversation $conversation): void
{
    $orchestrator = new InsuranceOrchestrator(Mockery::mock(WhatsAppAdapter::class));
    $method = (new ReflectionClass($orchestrator))->getMethod('syncCustomerIdentifiedState');
    $method->setAccessible(true);
    $method->invoke($orchestrator, $conversation);
}

it('ingest captures name and phone into a new customer', function () {
    dispatchIngestForCapture('5491112345678', 'US.13491208655302741918', 'Juan Perez');

    $this->assertDatabaseHas('customers', [
        'name' => 'Juan Perez',
        'phone' => '+5491112345678',
    ]);
});

it('ingest does not overwrite an existing customer name', function () {
    $bsuid = 'US.13491208655302741918';
    $customer = Customer::factory()->create(['name' => 'Nombre Real', 'phone' => null]);
    Conversation::factory()->create([
        'ext_user_id' => $bsuid,
        'external_conversation_id' => null,
        'status' => 'active',
        'customer_id' => $customer->id,
    ]);

    dispatchIngestForCapture('5491112345678', $bsuid, 'Nombre Del Webhook');

    expect($customer->fresh()->name)->toBe('Nombre Real');
});

it('ingest treats the Usuario placeholder as no name', function () {
    dispatchIngestForCapture(null, 'US.13491208655302741918', 'Usuario');

    // BSUID-only sin nombre real: el Customer nace sin name (no se guarda el placeholder).
    $this->assertDatabaseHas('customers', ['name' => null]);
});

it('marks customer_identified when the linked customer already has a DNI', function () {
    // Customer factory trae DNI por default — replica al cliente que vuelve ya identificado.
    $customer = Customer::factory()->create();
    $conversation = Conversation::factory()->create([
        'customer_id' => $customer->id,
        'metadata' => ['ai_state' => ['customer_identified' => false]],
    ]);

    invokeSyncIdentifiedState($conversation);

    expect($conversation->fresh()->aiState()['customer_identified'])->toBeTrue();
});

it('does NOT mark customer_identified when the customer has no DNI', function () {
    $customer = Customer::factory()->create(['dni' => null]);
    $conversation = Conversation::factory()->create([
        'customer_id' => $customer->id,
        'metadata' => ['ai_state' => ['customer_identified' => false]],
    ]);

    invokeSyncIdentifiedState($conversation);

    expect($conversation->fresh()->aiState()['customer_identified'])->toBeFalse();
});
