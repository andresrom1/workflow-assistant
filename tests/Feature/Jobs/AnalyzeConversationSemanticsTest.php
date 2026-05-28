<?php

use App\AI\Agents\ConversationAnalyzerAgent;
use App\Jobs\AnalyzeConversationSemanticsJob;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\AgentResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('ai.semantic_analysis.enabled', true);
    config()->set('ai.semantic_analysis.window_turns', 6);
    config()->set('ai.semantic_analysis.throttle_minutes', 5);
});

/**
 * Bindea un mock del ConversationAnalyzerAgent en el container para que
 * `ConversationAnalyzerAgent::make()` devuelva este mock en vez de tocar el LLM.
 */
function fakeAnalyzer(string $jsonResponse): void
{
    $response = Mockery::mock(AgentResponse::class);
    $response->text = $jsonResponse;

    $agent = Mockery::mock(ConversationAnalyzerAgent::class);
    $agent->shouldReceive('prompt')->andReturn($response);

    app()->instance(ConversationAnalyzerAgent::class, $agent);
}

function convoWithMessages(int $n = 2, ?Conversation $conv = null): Conversation
{
    $conv ??= Conversation::factory()->create();

    for ($i = 0; $i < $n; $i++) {
        Message::create([
            'conversation_id' => $conv->id,
            'direction' => $i % 2 === 0 ? 'inbound' : 'outbound',
            'type' => 'text',
            'content' => 'msg '.$i,
        ]);
    }

    return $conv->refresh();
}

it('short-circuits when feature flag is disabled', function () {
    config()->set('ai.semantic_analysis.enabled', false);

    $conv = convoWithMessages(2);

    // Si el mock no se invocó, el test pasa (agent no bindeado → excepción si lo invoca).
    (new AnalyzeConversationSemanticsJob($conv->id))->handle();

    $conv->refresh();
    expect($conv->semantic_analysis)->toBeNull()
        ->and($conv->last_semantic_analysis_at)->toBeNull();
});

it('force bypasses feature flag', function () {
    config()->set('ai.semantic_analysis.enabled', false);

    $conv = convoWithMessages(2);

    fakeAnalyzer(json_encode([
        'user_frustrated' => false, 'agent_confused' => false,
        'semantic_loop' => false, 'context_loss' => false,
        'hallucination' => false, 'incorrect_answer' => false,
        'reasoning' => [],
    ]));

    (new AnalyzeConversationSemanticsJob($conv->id, force: true))->handle();

    $conv->refresh();
    expect($conv->semantic_analysis)->not->toBeNull();
});

it('short-circuits when turn count is unchanged', function () {
    $conv = convoWithMessages(2);
    $conv->update(['semantic_analysis_turn_count' => 2]);

    (new AnalyzeConversationSemanticsJob($conv->id))->handle();

    $conv->refresh();
    expect($conv->semantic_analysis)->toBeNull();
});

it('short-circuits when throttle is active', function () {
    $conv = convoWithMessages(4);
    $conv->update([
        'last_semantic_analysis_at' => now()->subMinute(),
        'semantic_analysis_turn_count' => 2,
    ]);

    (new AnalyzeConversationSemanticsJob($conv->id))->handle();

    $conv->refresh();
    expect($conv->semantic_analysis)->toBeNull();
});

it('force bypasses throttle', function () {
    $conv = convoWithMessages(4);
    $conv->update([
        'last_semantic_analysis_at' => now()->subMinute(),
        'semantic_analysis_turn_count' => 2,
    ]);

    fakeAnalyzer(json_encode([
        'user_frustrated' => true, 'agent_confused' => false,
        'semantic_loop' => false, 'context_loss' => false,
        'hallucination' => false, 'incorrect_answer' => false,
        'reasoning' => ['user_frustrated' => 'tono agresivo'],
    ]));

    (new AnalyzeConversationSemanticsJob($conv->id, force: true))->handle();

    $conv->refresh();
    expect($conv->semantic_analysis)->not->toBeNull();
});

it('merges semantic flags without overwriting Tier 1 flags', function () {
    $conv = convoWithMessages(2);
    $conv->update(['flags' => ['stuck' => true, 'loops' => false]]);

    fakeAnalyzer(json_encode([
        'user_frustrated' => true, 'agent_confused' => false,
        'semantic_loop' => true, 'context_loss' => false,
        'hallucination' => false, 'incorrect_answer' => false,
        'reasoning' => [
            'user_frustrated' => 'insultos',
            'semantic_loop' => 'repite misma idea',
        ],
    ]));

    (new AnalyzeConversationSemanticsJob($conv->id, force: true))->handle();

    $conv->refresh();
    $flags = $conv->flags;

    expect($flags['stuck'])->toBeTrue()       // Tier 1 preservado
        ->and($flags['loops'])->toBeFalse()    // Tier 1 preservado
        ->and($flags['user_frustrated'])->toBeTrue()
        ->and($flags['semantic_loop'])->toBeTrue()
        ->and($flags['hallucination'])->toBeFalse();
});

it('parses JSON wrapped in markdown code fences', function () {
    $conv = convoWithMessages(2);

    $inner = json_encode([
        'user_frustrated' => true, 'agent_confused' => false,
        'semantic_loop' => false, 'context_loss' => false,
        'hallucination' => false, 'incorrect_answer' => false,
        'reasoning' => ['user_frustrated' => 'grita en mayúsculas'],
    ]);

    fakeAnalyzer("```json\n{$inner}\n```");

    (new AnalyzeConversationSemanticsJob($conv->id, force: true))->handle();

    $conv->refresh();
    expect($conv->semantic_analysis['flags']['user_frustrated'] ?? null)->toBeTrue();
});

it('records messages_analyzed and updates timestamp', function () {
    $conv = convoWithMessages(4);

    fakeAnalyzer(json_encode([
        'user_frustrated' => false, 'agent_confused' => false,
        'semantic_loop' => false, 'context_loss' => false,
        'hallucination' => false, 'incorrect_answer' => false,
        'reasoning' => [],
    ]));

    (new AnalyzeConversationSemanticsJob($conv->id, force: true))->handle();

    $conv->refresh();
    expect($conv->semantic_analysis['messages_analyzed'])->toBe(4)
        ->and($conv->semantic_analysis_turn_count)->toBe(4)
        ->and($conv->last_semantic_analysis_at)->not->toBeNull();
});
