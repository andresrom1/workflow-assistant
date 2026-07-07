<?php

use App\AI\InsuranceOrchestrator;
use App\Jobs\ProcessConversationInbox;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->waId = '5491112345678';
});

it('pauses the ai for a conversation', function () {
    $conversation = Conversation::factory()->create(['external_conversation_id' => $this->waId]);

    $this->actingAs($this->admin)
        ->post(route('admin.conversations.pause-ai', $conversation))
        ->assertRedirect();

    expect($conversation->fresh()->isAiPaused())->toBeTrue();
});

it('resumes the ai for a conversation', function () {
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
        'metadata' => ['ai_paused' => true, 'ai_paused_at' => now()->toIso8601String()],
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.conversations.resume-ai', $conversation))
        ->assertRedirect();

    expect($conversation->fresh()->isAiPaused())->toBeFalse();
});

it('does not call the orchestrator and does not dispatch outbound when the ai is paused', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldNotReceive('handle');

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
        'metadata' => ['ai_paused' => true],
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Hola, sigo esperando',
        'external_message_id' => 'wamid.paused001',
        'sender_name' => 'Test User',
        'sender_phone' => $this->waId,
    ]);

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, '123456789');

    expect($message->fresh()->processed_at)->not->toBeNull();
    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

it('sends a manual message with agent_name human', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
        'metadata' => ['ai_paused' => true],
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.conversations.send-message', $conversation), ['text' => 'Ya te ayudo yo'])
        ->assertRedirect();

    Bus::assertDispatched(SendWhatsAppMessage::class, function ($job) {
        $ref = new ReflectionClass($job);
        $agentName = tap($ref->getProperty('agentName'), fn ($p) => $p->setAccessible(true))->getValue($job);
        $text = tap($ref->getProperty('text'), fn ($p) => $p->setAccessible(true))->getValue($job);

        return $agentName === 'human' && $text === 'Ya te ayudo yo';
    });
});

it('requires text to send a manual message', function () {
    $conversation = Conversation::factory()->create(['external_conversation_id' => $this->waId]);

    $this->actingAs($this->admin)
        ->post(route('admin.conversations.send-message', $conversation), ['text' => ''])
        ->assertSessionHasErrors('text');
});

it('prepends a pause transcript to the first message after resuming', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $pausedAt = now()->subMinutes(10);
    $resumedAt = now()->subMinute();

    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $this->waId,
        'metadata' => [
            'ai_paused' => false,
            'ai_paused_at' => $pausedAt->toIso8601String(),
            'ai_resumed_at' => $resumedAt->toIso8601String(),
        ],
    ]);

    // created_at no es fillable (Message::$fillable) — se fuerza después del create().
    $duringPause1 = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Necesito ayuda urgente',
        'external_message_id' => 'wamid.transcript001',
        'sender_phone' => $this->waId,
        'processed_at' => $pausedAt->copy()->addMinute(),
    ]);
    $duringPause1->forceFill(['created_at' => $pausedAt->copy()->addMinute()])->saveQuietly();

    $duringPause2 = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'outbound',
        'agent_name' => 'human',
        'content' => 'Ya te reviso el caso',
    ]);
    $duringPause2->forceFill(['created_at' => $pausedAt->copy()->addMinutes(2)])->saveQuietly();

    $newMessage = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'Gracias, espero la cotización',
        'external_message_id' => 'wamid.transcript002',
        'sender_phone' => $this->waId,
    ]);

    $capturedBody = null;

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function ($body) use (&$capturedBody) {
            $capturedBody = $body;

            return ['text' => 'Dale', 'agent' => 'QuoteAgent', 'execution_log_ids' => []];
        });

    ProcessConversationInbox::dispatchSync($conversation->id, $this->waId, '123456789');

    expect($capturedBody)->toContain('[Contexto: un asesor humano atendió esta conversación')
        ->and($capturedBody)->toContain('Cliente: Necesito ayuda urgente')
        ->and($capturedBody)->toContain('Asesor: Ya te reviso el caso')
        ->and($capturedBody)->toContain('Gracias, espero la cotización');

    $conversation->refresh();
    expect($conversation->metadata)->not->toHaveKey('ai_paused_at')
        ->and($conversation->metadata)->not->toHaveKey('ai_resumed_at');
});
