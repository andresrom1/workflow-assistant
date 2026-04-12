<?php

use App\Models\AgentExecutionLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('renders the show page for admin users', function () {
    $conversation = Conversation::factory()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.conversations.show', $conversation))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Conversations/Show'));
});

it('returns 403 for non-admin users', function () {
    $conversation = Conversation::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.conversations.show', $conversation))
        ->assertForbidden();
});

it('returns 302 for unauthenticated users', function () {
    $conversation = Conversation::factory()->create();

    $this->get(route('admin.conversations.show', $conversation))
        ->assertRedirect('/login');
});

it('includes conversation metadata in the inertia response', function () {
    $conversation = Conversation::factory()->create([
        'channel' => 'whatsapp',
        'status' => 'active',
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.conversations.show', $conversation))
        ->assertInertia(fn ($page) => $page
            ->has('conversation')
            ->where('conversation.id', $conversation->id)
            ->where('conversation.channel', 'whatsapp')
            ->where('conversation.status', 'active')
        );
});

it('returns messages sorted by created_at', function () {
    $conversation = Conversation::factory()->create();

    $first = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'outbound',
        'content' => 'primero',
        'created_at' => now()->addMinute(),
    ]);

    $second = Message::create([
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'content' => 'segundo',
        'created_at' => now()->addMinutes(2),
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.conversations.show', $conversation))
        ->assertInertia(fn ($page) => $page
            ->has('messages', 2)
            ->where('messages.0.id', $first->id)
            ->where('messages.1.id', $second->id)
        );
});

it('returns execution logs for the conversation', function () {
    $conversation = Conversation::factory()->create();

    $log = AgentExecutionLog::factory()->forStep(1)->create([
        'conversation_id' => $conversation->id,
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.conversations.show', $conversation))
        ->assertInertia(fn ($page) => $page
            ->has('executions', 1)
            ->where('executions.0.id', $log->id)
            ->where('executions.0.agent_name', $log->agent_name)
        );
});

it('returns stats with correct total invocations and duration', function () {
    $conversation = Conversation::factory()->create();

    AgentExecutionLog::factory()->forStep(1)->create([
        'conversation_id' => $conversation->id,
        'duration_ms' => 1200,
    ]);

    AgentExecutionLog::factory()->forStep(2)->create([
        'conversation_id' => $conversation->id,
        'duration_ms' => 800,
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.conversations.show', $conversation))
        ->assertInertia(fn ($page) => $page
            ->where('stats.total_invocations', 2)
            ->where('stats.total_duration_ms', 2000)
        );
});

it('returns null token stats when no logs exist', function () {
    $conversation = Conversation::factory()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.conversations.show', $conversation))
        ->assertInertia(fn ($page) => $page
            ->where('stats.total_invocations', 0)
            ->where('stats.total_input_tokens', null)
            ->where('stats.total_output_tokens', null)
        );
});
