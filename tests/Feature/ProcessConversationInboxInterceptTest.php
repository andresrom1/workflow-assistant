<?php

use App\AI\InsuranceOrchestrator;
use App\Jobs\ProcessConversationInbox;
use App\Jobs\SendWhatsAppMessage;
use App\Models\AgentExecutionLog;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->waId = '5491112345678';
    $this->phoneNumberId = '123456789';
});

/**
 * Conversación con un mensaje entrante sin procesar, lista para correr un turno.
 */
function conversacionConEntrante(string $contenido, string $wamid): Conversation
{
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => '5491112345678',
        'customer_id' => Customer::factory()->create(['name' => null])->id,
    ]);

    entranteNuevo($conversation, $contenido, $wamid);

    return $conversation;
}

/**
 * Simula al cliente escribiendo otro mensaje mientras el LLM está generando la respuesta.
 */
function entranteNuevo(Conversation $conversation, string $contenido, string $wamid): void
{
    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => $contenido,
        'external_message_id' => $wamid,
        'sender_phone' => '5491112345678',
    ]);
}

function textoDelJob(SendWhatsAppMessage $job): string
{
    $propiedad = (new ReflectionClass($job))->getProperty('text');
    $propiedad->setAccessible(true);

    return (string) $propiedad->getValue($job);
}

/**
 * La ventana de silencio solo cubre lo que llega ANTES de arrancar el turno. Lo que llega
 * mientras el LLM genera se agarra acá: la respuesta ya vieja se descarta y el turno se rehace
 * con todo junto. La ventana de intercepción es el tiempo de generación que se gasta igual, así
 * que no agrega latencia — solo cuesta un turno extra de LLM cuando efectivamente se usa.
 */
it('descarta la respuesta y rehace el turno cuando llega un mensaje durante la generación', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $conversation = conversacionConEntrante('el codigo postal es 5000', 'wamid.cp');
    $cuerpos = [];

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->twice()
        ->andReturnUsing(function (string $body) use (&$cuerpos, $conversation): array {
            $cuerpos[] = $body;

            if (count($cuerpos) === 1) {
                entranteNuevo($conversation, 'la patente es AB123CD', 'wamid.patente');

                return ['text' => 'Gracias. ¿Me pasás la patente?', 'agent' => 'VehicleIdentifierAgent', 'execution_log_ids' => []];
            }

            return ['text' => 'Perfecto, anoté el CP 5000 y la patente AB123CD.', 'agent' => 'VehicleIdentifierAgent', 'execution_log_ids' => []];
        });

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    // Una sola respuesta, y es la segunda: la primera nunca salió.
    Bus::assertDispatchedTimes(SendWhatsAppMessage::class, 1);
    Bus::assertDispatched(
        SendWhatsAppMessage::class,
        fn (SendWhatsAppMessage $job): bool => str_contains(textoDelJob($job), 'anoté el CP 5000 y la patente')
    );

    expect($cuerpos[0])->toBe('el codigo postal es 5000')
        ->and($cuerpos[1])->toBe('la patente es AB123CD');

    $this->assertDatabaseMissing('messages', [
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'processed_at' => null,
    ]);
});

/**
 * `coverage_preference` ya disparó el pedido real a las compañías: descartar el texto no lo
 * deshace, y rehacer el turno podría dispararlo de nuevo. Se manda lo que haya.
 */
it('no intercepta un turno que disparó una tool irreversible', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $conversation = conversacionConEntrante('quiero terceros completo', 'wamid.cobertura');

    $log = AgentExecutionLog::factory()->create([
        'conversation_id' => $conversation->id,
        'tool_calls' => [['name' => 'coverage_preference', 'arguments' => []]],
    ]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function () use ($conversation, $log): array {
            entranteNuevo($conversation, 'perdon, mejor todo riesgo', 'wamid.arrepentido');

            return ['text' => 'Listo, ya lo tengo.', 'agent' => 'CoveragePreferenceAgent', 'execution_log_ids' => [$log->id]];
        });

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    Bus::assertDispatchedTimes(SendWhatsAppMessage::class, 1);
});

it('no intercepta un turno encadenado', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $conversation = conversacionConEntrante('dale', 'wamid.encadenado');

    $log = AgentExecutionLog::factory()->chained()->create([
        'conversation_id' => $conversation->id,
        'tool_calls' => [],
    ]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function () use ($conversation, $log): array {
            entranteNuevo($conversation, 'algo mas', 'wamid.durante');

            return ['text' => 'Acá van tus opciones.', 'agent' => 'CheckoutAgent', 'execution_log_ids' => [$log->id]];
        });

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    Bus::assertDispatchedTimes(SendWhatsAppMessage::class, 1);
});

/**
 * `pullPending()` LEE Y BORRA los botones y el link de la metadata al armar la respuesta. Si se
 * descartara esa respuesta sin arrastrarlos, el redo ya no los encontraría y el cliente se
 * quedaría sin botones ni link a la comparación.
 */
it('arrastra los botones y el link de la respuesta descartada', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $conversation = conversacionConEntrante('mostrame las opciones', 'wamid.opciones');
    $llamadas = 0;

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->twice()
        ->andReturnUsing(function () use (&$llamadas, $conversation): array {
            $llamadas++;

            if ($llamadas === 1) {
                entranteNuevo($conversation, 'y cuanto sale la mas cara?', 'wamid.repregunta');

                return [
                    'text' => 'Te dejo las dos mejores.',
                    'agent' => 'CheckoutAgent',
                    'execution_log_ids' => [],
                    'buttons' => [['id' => 'alt:1', 'title' => 'Galicia $90K']],
                    'public_link' => 'https://mango.test/cotizaciones/abcdefghijklmnop',
                ];
            }

            // El redo ya no los encuentra: pullPending() los consumió en el turno descartado.
            return [
                'text' => 'La más cara es la de Galicia.',
                'agent' => 'CheckoutAgent',
                'execution_log_ids' => [],
                'buttons' => null,
                'public_link' => null,
            ];
        });

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    // El link sobrevivió: sale encadenado detrás del mensaje que le da sentido.
    Bus::assertChained([SendWhatsAppMessage::class, SendWhatsAppMessage::class]);
});

/**
 * Se marca y no se borra: la fila del assistant carga también `tool_calls`, y el contexto del
 * modelo se reconstruye desde ahí. Borrarla le sacaría el registro de que la tool ya corrió, y
 * el redo tendería a re-ejecutarla.
 */
it('marca en la memoria del agente la respuesta que nunca salió', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $conversation = conversacionConEntrante('hola', 'wamid.memoria');

    $hiloId = (string) Str::uuid();
    DB::table('agent_conversations')->insert([
        'id' => $hiloId,
        'user_id' => $conversation->id,
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $llamadas = 0;

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->twice()
        ->andReturnUsing(function () use (&$llamadas, $conversation, $hiloId): array {
            $llamadas++;

            // El middleware del SDK escribe la fila del assistant al final de cada turno.
            DB::table('agent_conversation_messages')->insert([
                'id' => (string) Str::uuid(),
                'conversation_id' => $hiloId,
                'user_id' => $conversation->id,
                'agent' => 'App\AI\Agents\CustomerIdentifierAgent',
                'role' => 'assistant',
                'content' => "respuesta numero {$llamadas}",
                'attachments' => json_encode([]),
                'tool_calls' => json_encode([['id' => 'call_1', 'name' => 'identify_customer', 'arguments' => []]]),
                'tool_results' => json_encode([]),
                'usage' => json_encode([]),
                'meta' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($llamadas === 1) {
                entranteNuevo($conversation, 'ah, y otra cosa', 'wamid.otracosa');
            }

            return ['text' => "respuesta numero {$llamadas}", 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []];
        });

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    $filas = DB::table('agent_conversation_messages')
        ->where('user_id', $conversation->id)
        ->get()
        ->keyBy(fn (object $f): string => str_contains((string) $f->content, 'numero 1') ? 'primera' : 'segunda');

    expect($filas['primera']->content)->toContain('NO ENTREGADO')
        ->and($filas['segunda']->content)->not->toContain('NO ENTREGADO')
        ->and($filas['primera']->tool_calls)->toContain('identify_customer');
});

it('respeta el tope de intercepciones cuando el cliente no para de escribir', function () {
    Bus::fake([SendWhatsAppMessage::class]);
    config()->set('whatsapp.inbox_max_intercepts', 1);

    $conversation = conversacionConEntrante('uno', 'wamid.uno');
    $llamadas = 0;

    // Con tope 1: turno original + 1 intercepción = 2 llamadas, y corta aunque siga entrando.
    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->twice()
        ->andReturnUsing(function () use (&$llamadas, $conversation): array {
            $llamadas++;
            entranteNuevo($conversation, "mensaje {$llamadas}", "wamid.loop{$llamadas}");

            return ['text' => "respuesta {$llamadas}", 'agent' => 'CustomerIdentifierAgent', 'execution_log_ids' => []];
        });

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, $this->phoneNumberId);

    Bus::assertDispatchedTimes(SendWhatsAppMessage::class, 1);
});
