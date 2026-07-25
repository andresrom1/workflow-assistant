<?php

use App\Http\Controllers\Admin\ConversationController;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Inserta un hilo de memoria del SDK con el user_id dado y devuelve su id. */
function seedAgentThread(int|string|null $userId): string
{
    $id = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => $userId,
        'title' => 'hilo de test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function threadUserId(string $threadId): ?int
{
    $value = DB::table('agent_conversations')->where('id', $threadId)->value('user_id');

    return $value === null ? null : (int) $value;
}

/** Corre solo el up() de la migración de backfill (idempotente). */
function runBackfill(): void
{
    $migration = include database_path('migrations/2026_07_24_210238_backfill_agent_conversations_user_id.php');
    $migration->up();
}

it('re-keys a live thread from phone to conversation id', function () {
    $phone = '5493516280778';
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $phone,
        'status' => 'active',
    ]);
    $threadId = seedAgentThread($phone); // memoria vieja: keyeada por teléfono

    runBackfill();

    expect(threadUserId($threadId))->toBe($conversation->id);
});

it('leaves reset (null) threads untouched', function () {
    Conversation::factory()->create(['external_conversation_id' => '5491111111111', 'status' => 'active']);
    $threadId = seedAgentThread(null); // ya reseteada

    runBackfill();

    expect(threadUserId($threadId))->toBeNull();
});

it('leaves threads with no matching conversation untouched', function () {
    // Ningún external_conversation_id numérico coincide con este user_id.
    $threadId = seedAgentThread(999888777);

    runBackfill();

    expect(threadUserId($threadId))->toBe(999888777);
});

it('is idempotent — a second run does not re-touch an already re-keyed thread', function () {
    $phone = '5493516280778';
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => $phone,
        'status' => 'active',
    ]);
    $threadId = seedAgentThread($phone);

    runBackfill();
    runBackfill();

    expect(threadUserId($threadId))->toBe($conversation->id);
});

it('reset() nulls the agent thread by conversation id and archives the conversation', function () {
    $conversation = Conversation::factory()->create([
        'external_conversation_id' => '5493516280778',
        'status' => 'active',
    ]);
    // Memoria ya en el modelo nuevo: keyeada por conversation->id.
    $threadId = seedAgentThread($conversation->id);

    app(ConversationController::class)->reset($conversation);

    $conversation->refresh();

    expect($conversation->status)->toBe('archived')
        ->and(threadUserId($threadId))->toBeNull();
});
