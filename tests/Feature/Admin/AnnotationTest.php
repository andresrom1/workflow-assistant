<?php

use App\Models\AgentExecutionLog;
use App\Models\AgentExecutionLogAnnotation;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->conversation = Conversation::factory()->create();
    $this->log = AgentExecutionLog::factory()->forStep(1)->create([
        'conversation_id' => $this->conversation->id,
    ]);
});

it('creates a positive annotation for an execution log', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.execution-logs.annotations.store', $this->log), [
            'verdict' => true,
        ])
        ->assertRedirect();

    expect(AgentExecutionLogAnnotation::count())->toBe(1);

    $annotation = AgentExecutionLogAnnotation::first();
    expect($annotation->verdict)->toBeTrue()
        ->and($annotation->note)->toBeNull()
        ->and($annotation->user_id)->toBe($this->admin->id)
        ->and($annotation->agent_execution_log_id)->toBe($this->log->id);
});

it('creates a negative annotation with a note', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.execution-logs.annotations.store', $this->log), [
            'verdict' => false,
            'note' => 'Respondió algo que el usuario no preguntó.',
        ])
        ->assertRedirect();

    $annotation = AgentExecutionLogAnnotation::first();
    expect($annotation->verdict)->toBeFalse()
        ->and($annotation->note)->toBe('Respondió algo que el usuario no preguntó.');
});

it('updates the existing annotation on re-submit (unique per user+log)', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.execution-logs.annotations.store', $this->log), ['verdict' => false, 'note' => 'mal']);

    $this->actingAs($this->admin)
        ->post(route('admin.execution-logs.annotations.store', $this->log), ['verdict' => true]);

    expect(AgentExecutionLogAnnotation::count())->toBe(1);
    expect(AgentExecutionLogAnnotation::first()->verdict)->toBeTrue();
});

it('allows two different admins to annotate the same log independently', function () {
    $otherAdmin = User::factory()->admin()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.execution-logs.annotations.store', $this->log), ['verdict' => true]);

    $this->actingAs($otherAdmin)
        ->post(route('admin.execution-logs.annotations.store', $this->log), ['verdict' => false, 'note' => 'no lo veo']);

    expect(AgentExecutionLogAnnotation::count())->toBe(2);
});

it('deletes only the current admin annotation', function () {
    $otherAdmin = User::factory()->admin()->create();

    $mine = AgentExecutionLogAnnotation::create([
        'agent_execution_log_id' => $this->log->id,
        'user_id' => $this->admin->id,
        'verdict' => true,
    ]);
    $theirs = AgentExecutionLogAnnotation::create([
        'agent_execution_log_id' => $this->log->id,
        'user_id' => $otherAdmin->id,
        'verdict' => false,
    ]);

    $this->actingAs($this->admin)
        ->delete(route('admin.execution-logs.annotations.destroy', $this->log))
        ->assertRedirect();

    expect(AgentExecutionLogAnnotation::find($mine->id))->toBeNull();
    expect(AgentExecutionLogAnnotation::find($theirs->id))->not->toBeNull();
});

it('validates verdict is required', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.execution-logs.annotations.store', $this->log), [])
        ->assertSessionHasErrors('verdict');
});

it('validates note max length', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.execution-logs.annotations.store', $this->log), [
            'verdict' => false,
            'note' => str_repeat('a', 1001),
        ])
        ->assertSessionHasErrors('note');
});

it('rejects unauthenticated requests', function () {
    $this->post(route('admin.execution-logs.annotations.store', $this->log), ['verdict' => true])
        ->assertRedirect('/login');
});

it('rejects non-admin requests', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.execution-logs.annotations.store', $this->log), ['verdict' => true])
        ->assertForbidden();
});

it('includes annotations in the conversation show payload', function () {
    $otherAdmin = User::factory()->admin()->create();

    AgentExecutionLogAnnotation::create([
        'agent_execution_log_id' => $this->log->id,
        'user_id' => $this->admin->id,
        'verdict' => false,
        'note' => 'flojo',
    ]);
    AgentExecutionLogAnnotation::create([
        'agent_execution_log_id' => $this->log->id,
        'user_id' => $otherAdmin->id,
        'verdict' => true,
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.conversations.show', $this->conversation))
        ->assertInertia(fn ($page) => $page
            ->has('executions.0.annotations', 2)
        );
});
