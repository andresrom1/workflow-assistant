<?php

use App\Jobs\AnalyzeConversationHealthJob;
use App\Models\AgentExecutionLog;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function runHealth(Conversation $c): void
{
    (new AnalyzeConversationHealthJob($c->id))->handle();
    $c->refresh();
}

function flagsOf(Conversation $c): array
{
    return is_array($c->flags) ? $c->flags : [];
}

function msg(Conversation $c, string $direction, string $content): Message
{
    return Message::create([
        'conversation_id' => $c->id,
        'direction' => $direction,
        'type' => 'text',
        'content' => $content,
    ]);
}

it('flags loops when duplicate outbound content appears in last 10 messages', function () {
    $c = Conversation::factory()->create();
    $content = '¿Podés pasarme tu DNI por favor?';
    msg($c, 'outbound', $content);
    msg($c, 'outbound', '  '.strtoupper($content).'!  '); // mismo normalizado

    runHealth($c);

    expect(flagsOf($c)['loops'] ?? null)->toBeTrue();
});

it('does not flag loops for distinct outbound messages', function () {
    $c = Conversation::factory()->create();
    msg($c, 'outbound', 'Uno');
    msg($c, 'outbound', 'Dos');
    msg($c, 'outbound', 'Tres');

    runHealth($c);

    expect(flagsOf($c)['loops'] ?? null)->toBeFalse();
});

it('flags stuck when turns_in_current_step reaches 5', function () {
    $c = Conversation::factory()->create(['turns_in_current_step' => 4]);

    runHealth($c);

    expect(flagsOf($c)['stuck'] ?? null)->toBeTrue()
        ->and($c->turns_in_current_step)->toBe(5);
});

it('does not flag stuck under 5 turns in step', function () {
    $c = Conversation::factory()->create(['turns_in_current_step' => 1]);

    runHealth($c);

    expect(flagsOf($c)['stuck'] ?? null)->toBeFalse();
});

it('flags tool_errors when any execution log has status=failed', function () {
    $c = Conversation::factory()->create();
    AgentExecutionLog::factory()->forStep(1)->create([
        'conversation_id' => $c->id,
        'status' => 'failed',
    ]);

    runHealth($c);

    expect(flagsOf($c)['tool_errors'] ?? null)->toBeTrue();
});

it('flags abandoned when last_message_at is older than 24h and checkout not done', function () {
    $c = Conversation::factory()->create([
        'last_message_at' => now()->subHours(25),
        'metadata' => ['ai_state' => ['checkout_done' => false]],
    ]);

    runHealth($c);

    expect(flagsOf($c)['abandoned'] ?? null)->toBeTrue();
});

it('does not flag abandoned when checkout is done', function () {
    $c = Conversation::factory()->create([
        'last_message_at' => now()->subHours(48),
        'metadata' => ['ai_state' => ['checkout_done' => true]],
    ]);

    runHealth($c);

    expect(flagsOf($c)['abandoned'] ?? null)->toBeFalse();
});

it('flags long when messages_count >= 20', function () {
    $c = Conversation::factory()->create();
    for ($i = 0; $i < 20; $i++) {
        msg($c, 'inbound', "m{$i}");
    }

    runHealth($c);

    expect(flagsOf($c)['long'] ?? null)->toBeTrue();
});

it('increments turns_in_current_step and persists last_health_analysis_at', function () {
    $c = Conversation::factory()->create(['turns_in_current_step' => 2]);

    runHealth($c);

    expect($c->turns_in_current_step)->toBe(3)
        ->and($c->last_health_analysis_at)->not->toBeNull();
});
