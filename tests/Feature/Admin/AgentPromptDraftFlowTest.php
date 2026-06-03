<?php

use App\Models\AgentPrompt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// HISTORIA — por qué este test tuvo (y ya NO tiene) un backup/restore de disco.
//
// `AgentPromptController::promoteDraft()`/`::store()` solían llamar a un
// `writePromptFile()` que hacía `file_put_contents(resource_path('prompts/agents/*.md'))`,
// es decir, escribían un archivo VERSIONADO desde un request HTTP. Como el test
// "promotes a draft to active" ejercita el controller real promoviendo un draft stub
// de `checkout_closer`, pisaba `CheckoutAgent.md` con basura ("promoted draft content
// sufficiently long...") y el stub se llegó a commitear (b2e70e9), dejando rojo el
// CheckoutAgentPromptTest "desde siempre". El prompt real (203 líneas) se recuperó de 1504224.
//
// La mitigación interina fue respaldar el .md en beforeEach y restaurarlo en afterEach.
// La solución de fondo (2026-06-03) fue eliminar `writePromptFile()`: la DB
// (`agent_prompts`, cacheada) es la única fuente de verdad y el .md es solo seed/fallback.
// Ya no se escribe ningún archivo en runtime, así que el backup/restore desapareció.
// NO reintroducir escrituras a `resource_path()` desde un controller.

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->other = User::factory()->admin()->create();
    $this->agentKey = 'checkout_closer';

    $this->active = AgentPrompt::create([
        'agent_key' => $this->agentKey,
        'type' => 'agent',
        'content' => "# Checkout\n\nActive version content sufficiently long.",
        'version' => 1,
        'is_active' => true,
        'status' => AgentPrompt::STATUS_ACTIVE,
        'notes' => 'initial',
    ]);
});

// ─── createDraft ─────────────────────────────────────────────────────────────

it('creates a draft from the active version owned by the current admin', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.agent-prompts.drafts.create', $this->agentKey))
        ->assertRedirect(route('admin.agent-prompts.show', $this->agentKey));

    $draft = AgentPrompt::draftFor($this->agentKey);

    expect($draft)->not->toBeNull()
        ->and($draft->status)->toBe(AgentPrompt::STATUS_DRAFT)
        ->and($draft->owner_id)->toBe($this->admin->id)
        ->and($draft->parent_version_id)->toBe($this->active->id)
        ->and($draft->content)->toBe($this->active->content)
        ->and($draft->is_active)->toBeFalse();
});

it('prevents a second concurrent draft for the same agent_key', function () {
    AgentPrompt::create([
        'agent_key' => $this->agentKey,
        'type' => 'agent',
        'content' => 'existing draft content for this agent key.',
        'version' => 2,
        'is_active' => false,
        'status' => AgentPrompt::STATUS_DRAFT,
        'owner_id' => $this->other->id,
        'parent_version_id' => $this->active->id,
    ]);

    $this->actingAs($this->admin)
        ->from(route('admin.agent-prompts.show', $this->agentKey))
        ->post(route('admin.agent-prompts.drafts.create', $this->agentKey))
        ->assertRedirect(route('admin.agent-prompts.show', $this->agentKey))
        ->assertSessionHas('error');

    expect(AgentPrompt::forAgent($this->agentKey)->draft()->count())->toBe(1);
});

// ─── updateDraft ─────────────────────────────────────────────────────────────

it('lets the owner update the draft content', function () {
    $draft = AgentPrompt::create([
        'agent_key' => $this->agentKey,
        'type' => 'agent',
        'content' => 'original content long enough to pass the min length rule',
        'version' => 2,
        'is_active' => false,
        'status' => AgentPrompt::STATUS_DRAFT,
        'owner_id' => $this->admin->id,
        'parent_version_id' => $this->active->id,
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.agent-prompts.drafts.update', $draft), [
            'content' => 'new content from the owner that is definitely long enough',
            'notes' => 'probando prompt',
        ])
        ->assertRedirect();

    $draft->refresh();
    expect($draft->content)->toBe('new content from the owner that is definitely long enough')
        ->and($draft->notes)->toBe('probando prompt');
});

it('blocks updates from a non-owner admin', function () {
    $draft = AgentPrompt::create([
        'agent_key' => $this->agentKey,
        'type' => 'agent',
        'content' => 'original content long enough to pass the min length rule',
        'version' => 2,
        'is_active' => false,
        'status' => AgentPrompt::STATUS_DRAFT,
        'owner_id' => $this->other->id,
        'parent_version_id' => $this->active->id,
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.agent-prompts.drafts.update', $draft), [
            'content' => 'intentando pisar contenido ajeno aunque sea admin',
        ])
        ->assertForbidden();
});

// ─── promoteDraft ────────────────────────────────────────────────────────────

it('promotes a draft to active, archives the previous active, and invalidates cache', function () {
    // Precargar cache con la versión activa actual
    AgentPrompt::activeFor($this->agentKey);
    expect(Cache::has("agent_prompt:{$this->agentKey}"))->toBeTrue();

    $draft = AgentPrompt::create([
        'agent_key' => $this->agentKey,
        'type' => 'agent',
        'content' => 'promoted draft content sufficiently long to pass validation',
        'version' => 2,
        'is_active' => false,
        'status' => AgentPrompt::STATUS_DRAFT,
        'owner_id' => $this->admin->id,
        'parent_version_id' => $this->active->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.agent-prompts.drafts.promote', $draft))
        ->assertRedirect();

    $draft->refresh();
    $this->active->refresh();

    expect($draft->is_active)->toBeTrue()
        ->and($draft->status)->toBe(AgentPrompt::STATUS_ACTIVE)
        ->and($draft->owner_id)->toBeNull()
        ->and($draft->version)->toBeGreaterThan(1)
        ->and($this->active->is_active)->toBeFalse()
        ->and($this->active->status)->toBe(AgentPrompt::STATUS_ARCHIVED)
        ->and(Cache::has("agent_prompt:{$this->agentKey}"))->toBeFalse();
});

it('blocks promote from a non-owner admin', function () {
    $draft = AgentPrompt::create([
        'agent_key' => $this->agentKey,
        'type' => 'agent',
        'content' => 'draft content that should not be promoted by a stranger',
        'version' => 2,
        'is_active' => false,
        'status' => AgentPrompt::STATUS_DRAFT,
        'owner_id' => $this->other->id,
        'parent_version_id' => $this->active->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.agent-prompts.drafts.promote', $draft))
        ->assertForbidden();
});

// ─── takeDraftControl ────────────────────────────────────────────────────────

it('reassigns ownership when take-control is invoked', function () {
    $draft = AgentPrompt::create([
        'agent_key' => $this->agentKey,
        'type' => 'agent',
        'content' => 'draft owned initially by the other admin, long enough.',
        'version' => 2,
        'is_active' => false,
        'status' => AgentPrompt::STATUS_DRAFT,
        'owner_id' => $this->other->id,
        'parent_version_id' => $this->active->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.agent-prompts.drafts.take-control', $draft))
        ->assertRedirect();

    $draft->refresh();
    expect($draft->owner_id)->toBe($this->admin->id);
});

// ─── discardDraft ────────────────────────────────────────────────────────────

it('lets the owner discard a draft', function () {
    $draft = AgentPrompt::create([
        'agent_key' => $this->agentKey,
        'type' => 'agent',
        'content' => 'discardable draft content long enough to satisfy validation',
        'version' => 2,
        'is_active' => false,
        'status' => AgentPrompt::STATUS_DRAFT,
        'owner_id' => $this->admin->id,
        'parent_version_id' => $this->active->id,
    ]);

    $this->actingAs($this->admin)
        ->delete(route('admin.agent-prompts.drafts.discard', $draft))
        ->assertRedirect();

    expect(AgentPrompt::find($draft->id))->toBeNull();
});

it('blocks discard from a non-owner admin', function () {
    $draft = AgentPrompt::create([
        'agent_key' => $this->agentKey,
        'type' => 'agent',
        'content' => 'someone else owns this draft and it should survive the attempt',
        'version' => 2,
        'is_active' => false,
        'status' => AgentPrompt::STATUS_DRAFT,
        'owner_id' => $this->other->id,
        'parent_version_id' => $this->active->id,
    ]);

    $this->actingAs($this->admin)
        ->delete(route('admin.agent-prompts.drafts.discard', $draft))
        ->assertForbidden();

    expect(AgentPrompt::find($draft->id))->not->toBeNull();
});

// ─── Model invariant ─────────────────────────────────────────────────────────

it('throws DomainException when trying to save a second draft for the same agent_key', function () {
    AgentPrompt::create([
        'agent_key' => $this->agentKey,
        'type' => 'agent',
        'content' => 'first draft content long enough to pass the validator rule',
        'version' => 2,
        'is_active' => false,
        'status' => AgentPrompt::STATUS_DRAFT,
        'owner_id' => $this->admin->id,
        'parent_version_id' => $this->active->id,
    ]);

    expect(fn () => AgentPrompt::create([
        'agent_key' => $this->agentKey,
        'type' => 'agent',
        'content' => 'second draft that should be rejected by the model invariant',
        'version' => 3,
        'is_active' => false,
        'status' => AgentPrompt::STATUS_DRAFT,
        'owner_id' => $this->other->id,
        'parent_version_id' => $this->active->id,
    ]))->toThrow(DomainException::class);
});
