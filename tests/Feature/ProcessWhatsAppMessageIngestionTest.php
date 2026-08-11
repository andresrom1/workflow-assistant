<?php

use App\Jobs\ProcessConversationInbox;
use App\Jobs\ProcessWhatsAppMessage;
use App\Jobs\SendWhatsAppMessage;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->waId = '5491112345678';
    $this->messageId = 'wamid.test123';
    $this->phoneNumberId = '123456789';
});

it('persists inbound message with null processed at', function () {
    Bus::fake([ProcessConversationInbox::class]);

    dispatchIngestion($this->waId, $this->messageId, $this->phoneNumberId);

    $this->assertDatabaseHas('messages', [
        'external_message_id' => $this->messageId,
        'direction' => 'inbound',
        'content' => 'Hola quiero asegurar un auto',
        'processed_at' => null,
    ]);
});

it('dispatches inbox processor on whatsapp ai queue', function () {
    Bus::fake([ProcessConversationInbox::class]);

    dispatchIngestion($this->waId, $this->messageId, $this->phoneNumberId);

    Bus::assertDispatched(ProcessConversationInbox::class, function ($job) {
        return $job->queue === 'whatsapp-ai';
    });
});

/**
 * Tildes azules y "escribiendo…" salen ACÁ, en la cola de ingesta, que no espera a nadie.
 *
 * La cola de IA sí puede estar ocupada: en la conversación #19 de prod estuvo ~50s presentando
 * una cotización, y durante todo ese rato los mensajes del cliente quedaron en gris — escribió
 * "¿estás bloqueado?". El acuse tiene que ser independiente de cuándo arranca el turno.
 */
it('acusa recibo y muestra escribiendo apenas ingesta el mensaje', function () {
    Bus::fake([ProcessConversationInbox::class]);

    $waService = $this->mock(WhatsAppOutboundService::class);
    $waService->shouldReceive('sendTypingIndicator')
        ->once()
        ->with($this->messageId, $this->phoneNumberId);

    dispatchIngestion($this->waId, $this->messageId, $this->phoneNumberId);
});

it('does not dispatch send whatsapp message', function () {
    Bus::fake([ProcessConversationInbox::class, SendWhatsAppMessage::class]);

    dispatchIngestion($this->waId, $this->messageId, $this->phoneNumberId);

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

it('ignores duplicate wamid', function () {
    Bus::fake([ProcessConversationInbox::class]);
    Cache::put('processed_wamid_'.$this->messageId, true, now()->addDay());

    dispatchIngestion($this->waId, $this->messageId, $this->phoneNumberId);

    $this->assertDatabaseMissing('messages', [
        'external_message_id' => $this->messageId,
    ]);

    Bus::assertNotDispatched(ProcessConversationInbox::class);
});

it('skips non text messages without dispatching inbox', function () {
    Bus::fake([ProcessConversationInbox::class]);

    ProcessWhatsAppMessage::dispatchSync(
        waId: $this->waId,
        messageBody: '',
        messageId: $this->messageId,
        phoneNumberId: $this->phoneNumberId,
        contactName: 'Test User',
        messageType: 'image',
    );

    $this->assertDatabaseMissing('messages', [
        'external_message_id' => $this->messageId,
    ]);

    Bus::assertNotDispatched(ProcessConversationInbox::class);
});

it('creates conversation if not exists', function () {
    Bus::fake([ProcessConversationInbox::class]);

    $this->assertDatabaseMissing('conversations', [
        'external_conversation_id' => $this->waId,
    ]);

    dispatchIngestion($this->waId, $this->messageId, $this->phoneNumberId);

    $this->assertDatabaseHas('conversations', [
        'external_conversation_id' => $this->waId,
        'channel' => 'whatsapp',
    ]);
});

it('reuses existing conversation for same wa id', function () {
    Bus::fake([ProcessConversationInbox::class]);

    dispatchIngestion($this->waId, $this->messageId, $this->phoneNumberId);

    ProcessWhatsAppMessage::dispatchSync(
        waId: $this->waId,
        messageBody: 'Es un Renault Sandero',
        messageId: 'wamid.test456',
        phoneNumberId: $this->phoneNumberId,
        contactName: 'Test User',
    );

    $this->assertDatabaseCount('conversations', 1);
    $this->assertDatabaseCount('messages', 2);
});

it('persists both phone and bsuid when both are present', function () {
    Bus::fake([ProcessConversationInbox::class]);

    ProcessWhatsAppMessage::dispatchSync(
        waId: $this->waId,
        messageBody: 'Hola',
        messageId: $this->messageId,
        phoneNumberId: $this->phoneNumberId,
        contactName: 'Test User',
        extUserId: 'US.13491208655302741918',
    );

    // La conversación se ancla en el BSUID (identidad estable). external_conversation_id
    // queda NULL (vestigio en retiro); el teléfono es atributo del Customer, no la clave.
    $this->assertDatabaseHas('conversations', [
        'external_conversation_id' => null,
        'ext_user_id' => 'US.13491208655302741918',
        'channel' => 'whatsapp',
    ]);

    $this->assertDatabaseHas('customers', [
        'phone' => '+5491112345678',
        'name' => 'Test User',
    ]);

    $this->assertDatabaseHas('messages', [
        'external_message_id' => $this->messageId,
        'sender_phone' => $this->waId,
    ]);
});

it('ingests a bsuid-only message and routes the conversation by bsuid', function () {
    Bus::fake([ProcessConversationInbox::class]);

    ProcessWhatsAppMessage::dispatchSync(
        waId: null,
        messageBody: 'Hola, llego sin teléfono',
        messageId: $this->messageId,
        phoneNumberId: $this->phoneNumberId,
        contactName: 'Test User',
        extUserId: 'US.13491208655302741918',
    );

    // Sin teléfono, la conversación se ancla igual en el BSUID; external_conversation_id NULL.
    $this->assertDatabaseHas('conversations', [
        'external_conversation_id' => null,
        'ext_user_id' => 'US.13491208655302741918',
        'channel' => 'whatsapp',
    ]);

    // El Customer se materializa igual (BSUID-only): solo con el nombre del perfil, sin teléfono.
    $this->assertDatabaseHas('customers', [
        'name' => 'Test User',
        'phone' => null,
    ]);

    // El mensaje se persiste con sender_phone nulo.
    $this->assertDatabaseHas('messages', [
        'external_message_id' => $this->messageId,
        'content' => 'Hola, llego sin teléfono',
        'sender_phone' => null,
    ]);

    Bus::assertDispatched(ProcessConversationInbox::class);
});

it('reuses the same conversation and customer for a repeat bsuid', function () {
    Bus::fake([ProcessConversationInbox::class]);
    $bsuid = 'US.13491208655302741918';

    ProcessWhatsAppMessage::dispatchSync(waId: $this->waId, messageBody: 'Hola', messageId: 'wamid.a', phoneNumberId: $this->phoneNumberId, contactName: 'Test User', extUserId: $bsuid);
    ProcessWhatsAppMessage::dispatchSync(waId: $this->waId, messageBody: 'Segundo', messageId: 'wamid.b', phoneNumberId: $this->phoneNumberId, contactName: 'Test User', extUserId: $bsuid);

    $this->assertDatabaseCount('conversations', 1);
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseCount('messages', 2);
});

it('two different bsuids sharing a phone are the same customer', function () {
    Bus::fake([ProcessConversationInbox::class]);

    ProcessWhatsAppMessage::dispatchSync(waId: $this->waId, messageBody: 'Hola', messageId: 'wamid.a', phoneNumberId: $this->phoneNumberId, contactName: 'A', extUserId: 'US.11111111111111111');
    ProcessWhatsAppMessage::dispatchSync(waId: $this->waId, messageBody: 'Hola', messageId: 'wamid.b', phoneNumberId: $this->phoneNumberId, contactName: 'B', extUserId: 'US.22222222222222222');

    // Dos BSUIDs distintos son dos conversaciones (la conversación es del canal)...
    $this->assertDatabaseCount('conversations', 2);

    // ...pero un solo cliente: el mismo teléfono es el mismo cliente. Antes esta prueba
    // afirmaba lo contrario ("no se deduplica por teléfono", del refactor a BSUID del
    // 2026-07-24); esa regla se revirtió al aparecer clientes duplicados en producción.
    // Ver ROADMAP, bitácora 2026-07-26.
    $this->assertDatabaseCount('customers', 1);
});

function dispatchIngestion(string $waId, string $messageId, string $phoneNumberId): void
{
    ProcessWhatsAppMessage::dispatchSync(
        waId: $waId,
        messageBody: 'Hola quiero asegurar un auto',
        messageId: $messageId,
        phoneNumberId: $phoneNumberId,
        contactName: 'Test User',
    );
}
