<?php

use App\AI\InsuranceOrchestrator;
use App\Jobs\ProcessConversationInbox;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->waId = '5491112345678';
    $this->bsuid = 'US.13491208655302741918';
    $this->phoneNumberId = '123456789';
});

it('processes single message and dispatches outbound', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->with('Hola quiero asegurar un auto', Mockery::type(Conversation::class))
        ->andReturn(['text' => 'Perfecto, ¿qué vehículo querés asegurar?', 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []]);

    // Sin nombre → no se antepone contexto; el body llega tal cual.
    $customer = Customer::factory()->create(['name' => null]);
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
        'customer_id' => $customer->id,
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola quiero asegurar un auto',
        'external_message_id' => 'wamid.test001',
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    $this->assertNotNull($message->fresh()->processed_at);

    Bus::assertDispatched(SendWhatsAppMessage::class, function ($job) {
        return $job->queue === 'whatsapp-outbound';
    });
});

it('dispatches outbound with the bsuid as recipient when there is no phone', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->andReturn(['text' => 'Hola, ¿en qué te ayudo?', 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []]);

    // Conversación solo-BSUID: external_conversation_id == ext_user_id == BSUID.
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->bsuid,
        'ext_user_id' => $this->bsuid,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola',
        'external_message_id' => 'wamid.bsuid001',
        'sender_name' => 'Test User',
        'sender_phone' => null,
    ]);

    // Sin teléfono fresco del webhook.
    ProcessConversationInbox::dispatchSync($conversation->id, null, $this->phoneNumberId);

    Bus::assertDispatched(SendWhatsAppMessage::class, function ($job) {
        $ref = new ReflectionClass($job);
        $phone = tap($ref->getProperty('phone'), fn ($p) => $p->setAccessible(true))->getValue($job);
        $bsuid = tap($ref->getProperty('bsuid'), fn ($p) => $p->setAccessible(true))->getValue($job);

        return $phone === null && $bsuid === $this->bsuid;
    });
});

it('concatenates multiple messages with newline', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->with("Hola quiero asegurar un auto\nEs un Renault Sandero", Mockery::type(Conversation::class))
        ->andReturn(['text' => 'Anotado el Renault Sandero. ¿Qué cobertura preferís?', 'agent' => 'VehicleIdentifierAgent', 'execution_log_ids' => []]);

    // Sin nombre → no se antepone contexto; el body concatenado llega tal cual.
    $customer = Customer::factory()->create(['name' => null]);
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
        'customer_id' => $customer->id,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola quiero asegurar un auto',
        'external_message_id' => 'wamid.test002',
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Es un Renault Sandero',
        'external_message_id' => 'wamid.test003',
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    $this->assertDatabaseMissing('messages', [
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'processed_at' => null,
    ]);
});

it('marks messages processed before calling ai', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola',
        'external_message_id' => 'wamid.test004',
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
    ]);

    $processedAtDuringAiCall = null;

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function () use ($message, &$processedAtDuringAiCall) {
            $processedAtDuringAiCall = $message->fresh()->processed_at;

            return ['text' => 'Respuesta', 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []];
        });

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    $this->assertNotNull($processedAtDuringAiCall, 'processed_at debería estar seteado antes de llamar al AI');
});

it('exits cleanly when inbox is empty', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldNotReceive('handle');

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

it('does not reprocess already processed messages', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldNotReceive('handle');

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Mensaje ya procesado',
        'external_message_id' => 'wamid.test005',
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
        'processed_at' => now()->subMinutes(5),
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

it('prepends the customer name to the body on the first turn (for the greeting)', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $captured = null;
    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')->once()->andReturnUsing(function (string $body) use (&$captured) {
        $captured = $body;

        return ['text' => 'Hola Juan', 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []];
    });

    $customer = Customer::factory()->create(['name' => 'Juan Perez']);
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola',
        'external_message_id' => 'wamid.name001',
        'sender_name' => 'Juan Perez',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    expect($captured)->toContain('Juan Perez')->toContain('Hola');
});

it('does not prepend the name once the agent memory thread already exists', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $captured = null;
    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')->once()->andReturnUsing(function (string $body) use (&$captured) {
        $captured = $body;

        return ['text' => 'Dale', 'agent' => 'VehicleIdentifierAgent', 'execution_log_ids' => []];
    });

    $customer = Customer::factory()->create(['name' => 'Juan Perez']);
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    // Ya hay hilo de memoria para esta conversación → no es el primer turno.
    DB::table('agent_conversations')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $conversation->id,
        'title' => 'hilo previo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Es un Gol',
        'external_message_id' => 'wamid.name002',
        'sender_name' => 'Juan Perez',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    expect($captured)->toBe('Es un Gol');
});

it('ignores outbound messages in inbox query', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldNotReceive('handle');

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
    ]);

    // Solo mensaje outbound sin procesar — no debe disparar AI
    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'outbound',
        'content' => 'Respuesta anterior del bot',
        'external_message_id' => 'wamid.outbound001',
        'sender_name' => 'Bot',
        'sender_phone' => $this->phoneNumberId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

// ─── Link a la vista pública ───────────────────────────────────────────────────────────────────
// Cuando el turno presentó opciones, el link a la comparación sale en un mensaje aparte
// inmediatamente después. Encadenado y no dos dispatch sueltos: los dos van a la misma cola y con
// más de un worker el link puede adelantarse al mensaje que le da sentido.

it('manda el link de la comparación en un segundo mensaje, detrás del primero', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')->once()->andReturn([
        'text' => 'Te dejo las dos mejores opciones.',
        'agent' => 'QuoteAgent',
        'execution_log_ids' => [],
        'buttons' => [['id' => 'alt:1', 'title' => 'Galicia $90K']],
        'public_link' => 'https://mango.test/cotizaciones/abcdefghijklmnop',
    ]);

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
        'customer_id' => Customer::factory()->create(['name' => null])->id,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Dale, mostrame',
        'external_message_id' => 'wamid.link001',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    Bus::assertChained([SendWhatsAppMessage::class, SendWhatsAppMessage::class]);
});

it('sin link presentado sigue saliendo un solo mensaje', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')->once()->andReturn([
        'text' => '¿Qué auto querés asegurar?',
        'agent' => 'VehicleIdentifierAgent',
        'execution_log_ids' => [],
        'buttons' => null,
        'public_link' => null,
    ]);

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
        'customer_id' => Customer::factory()->create(['name' => null])->id,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola',
        'external_message_id' => 'wamid.link002',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    Bus::assertDispatchedTimes(SendWhatsAppMessage::class, 1);
    Bus::assertDispatched(SendWhatsAppMessage::class, fn ($job): bool => $job->chained === []);
});

// ---------------------------------------------------------------------------
// Ventana de silencio deslizante
// ---------------------------------------------------------------------------

/**
 * La versión anterior era una ventana FIJA desde cada mensaje: el primer job que vencía
 * barría lo que hubiera y arrancaba, estuviera el cliente escribiendo o no. Ahora la ventana
 * se mide contra el mensaje MÁS NUEVO, así que cada mensaje la corre.
 */
it('difiere el turno cuando el cliente sigue escribiendo', function () {
    Bus::fake([SendWhatsAppMessage::class]);
    config()->set('whatsapp.inbox_quiet_seconds', 10);

    // El orquestador no se debe tocar: la gracia es no gastar una llamada al LLM.
    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldNotReceive('handle');

    $conversation = Conversation::factory()->create(['external_conversation_id' => $this->waId]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'el codigo postal es 5000',
        'external_message_id' => 'wamid.quiet001',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    expect($message->fresh()->processed_at)->toBeNull();
    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

it('procesa igual cuando se supera el tope duro de espera', function () {
    Bus::fake([SendWhatsAppMessage::class]);
    config()->set('whatsapp.inbox_quiet_seconds', 30);
    config()->set('whatsapp.inbox_max_wait_seconds', 5);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->andReturn(['text' => 'Listo', 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []]);

    $conversation = Conversation::factory()->create(['external_conversation_id' => $this->waId]);

    // Más viejo que el tope: aunque el cliente siguiera escribiendo, ya no se difiere más.
    $message = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'sigo escribiendo sin parar',
        'external_message_id' => 'wamid.quiet002',
        'sender_phone' => $this->waId,
    ]);
    $message->forceFill(['created_at' => now()->subSeconds(60)])->save();

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    expect($message->fresh()->processed_at)->not->toBeNull();
    Bus::assertDispatched(SendWhatsAppMessage::class);
});

// ---------------------------------------------------------------------------
// Typing indicator
// ---------------------------------------------------------------------------

/**
 * Se ancla al wamid del ÚLTIMO mensaje entrante y sale al empezar el turno, no al enviar:
 * en el envío la respuesta ya está lista y el indicador se vería medio segundo.
 */
it('muestra el typing indicator anclado al último mensaje entrante', function () {
    Bus::fake([SendWhatsAppMessage::class]);
    config()->set('whatsapp.typing_indicator_enabled', true);

    $waService = $this->mock(WhatsAppOutboundService::class);
    $waService->shouldReceive('sendTypingIndicator')
        ->once()
        ->with('wamid.ultimo', $this->phoneNumberId);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->andReturn(['text' => 'Listo', 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []]);

    $conversation = Conversation::factory()->create(['external_conversation_id' => $this->waId]);

    foreach (['wamid.primero' => 'hola', 'wamid.ultimo' => 'quiero cotizar'] as $wamid => $texto) {
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => $texto,
            'external_message_id' => $wamid,
            'sender_phone' => $this->waId,
        ]);
    }

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);
});

it('no muestra el typing indicator cuando está apagado por config', function () {
    Bus::fake([SendWhatsAppMessage::class]);
    config()->set('whatsapp.typing_indicator_enabled', false);

    $waService = $this->mock(WhatsAppOutboundService::class);
    $waService->shouldNotReceive('sendTypingIndicator');

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->andReturn(['text' => 'Listo', 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []]);

    $conversation = Conversation::factory()->create(['external_conversation_id' => $this->waId]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'hola',
        'external_message_id' => 'wamid.sinindicador',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);
});
