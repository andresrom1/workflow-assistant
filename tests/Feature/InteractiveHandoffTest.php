<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\InsuranceOrchestrator;
use App\Jobs\ProcessConversationInbox;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('propagates buttons from the orchestrator reply through to SendWhatsAppMessage', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $waId = '5491112345678';
    $conversation = Conversation::factory()->create(['external_conversation_id' => $waId]);

    Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Dale, cotizame',
        'external_message_id' => 'wamid.handoff001',
        'sender_phone' => $waId,
    ]);

    $buttons = [['id' => 'alt:1', 'title' => 'Sancor $45K'], ['id' => 'question', 'title' => 'Tengo una pregunta']];

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->andReturn(['text' => 'Presentando opciones', 'agent' => 'CheckoutAgent', 'execution_log_ids' => [], 'buttons' => $buttons]);

    ProcessConversationInbox::dispatchSync($conversation->id, $waId, '123456789');

    Bus::assertDispatched(SendWhatsAppMessage::class, function ($job) use ($buttons) {
        $ref = new ReflectionClass($job);
        $jobButtons = tap($ref->getProperty('buttons'), fn ($p) => $p->setAccessible(true))->getValue($job);

        return $jobButtons === $buttons;
    });
});

it('pulls and clears pending_interactive from conversation metadata on handle()', function () {
    $waId = '5491112345679';
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $waId,
        'metadata' => [
            'ai_state' => [
                'customer_identified' => true,
                'vehicle_identified' => true,
                'coverage_set' => true,
                'quote_ready' => true,
                'checkout_done' => false,
            ],
            'pending_interactive' => ['buttons' => [['id' => 'alt:9', 'title' => 'Test $1K']]],
            'pending_public_link' => 'https://mango.test/cotizaciones/abcdefghijklmnop',
            // Sin el sello, `pullPending()` los toma por basura de un turno muerto.
            'pending_at' => now()->toIso8601String(),
        ],
    ]);

    // Reflexión sobre el método privado pullPending, ya que probarlo vía handle()
    // completo requeriría mockear la llamada real al LLM del CheckoutAgent.
    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $orchestrator = new InsuranceOrchestrator($adapter);

    $method = (new ReflectionClass($orchestrator))->getMethod('pullPending');
    $method->setAccessible(true);
    $pendiente = $method->invoke($orchestrator, $conversation);

    expect($pendiente['buttons'])->toBe([['id' => 'alt:9', 'title' => 'Test $1K']])
        ->and($pendiente['public_link'])->toBe('https://mango.test/cotizaciones/abcdefghijklmnop');

    // Las dos claves se consumen: si el link sobreviviera al turno, saldría de nuevo en el
    // siguiente mensaje del agente.
    $conversation->refresh();
    expect($conversation->metadata)->not->toHaveKey('pending_interactive')
        ->and($conversation->metadata)->not->toHaveKey('pending_public_link');
});

it('returns null buttons when nothing is pending', function () {
    $conversation = Conversation::factory()->create([
        'metadata' => ['ai_state' => ['customer_identified' => true, 'vehicle_identified' => false, 'coverage_set' => false, 'quote_ready' => false, 'checkout_done' => false]],
    ]);

    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $orchestrator = new InsuranceOrchestrator($adapter);

    $method = (new ReflectionClass($orchestrator))->getMethod('pullPending');
    $method->setAccessible(true);
    $pendiente = $method->invoke($orchestrator, $conversation);

    expect($pendiente['buttons'])->toBeNull()
        ->and($pendiente['public_link'])->toBeNull();
});
