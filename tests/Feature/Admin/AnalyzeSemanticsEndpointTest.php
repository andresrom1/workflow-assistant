<?php

use App\Jobs\AnalyzeConversationSemanticsJob;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    config()->set('ai.semantic_analysis.enabled', true);
});

it('dispatches the semantic analysis job with force=true', function () {
    Bus::fake();
    $conversation = Conversation::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.conversations.analyze-semantics', $conversation))
        ->assertRedirect()
        ->assertSessionHas('success');

    Bus::assertDispatched(AnalyzeConversationSemanticsJob::class, function ($job) use ($conversation) {
        return $job->conversationId === $conversation->id && $job->force === true;
    });
});

it('does not dispatch when feature flag is off', function () {
    config()->set('ai.semantic_analysis.enabled', false);
    Bus::fake();
    $conversation = Conversation::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.conversations.analyze-semantics', $conversation))
        ->assertRedirect()
        ->assertSessionHas('error');

    Bus::assertNotDispatched(AnalyzeConversationSemanticsJob::class);
});

it('returns 403 for non-admin users', function () {
    Bus::fake();
    $user = User::factory()->create();
    $conversation = Conversation::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.conversations.analyze-semantics', $conversation))
        ->assertForbidden();

    Bus::assertNotDispatched(AnalyzeConversationSemanticsJob::class);
});

it('includes semantic_analysis_enabled flag in show payload', function () {
    $conversation = Conversation::factory()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.conversations.show', $conversation))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('semantic_analysis_enabled', true)
        );
});
