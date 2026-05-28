<?php

use App\Models\AgentPrompt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->prompt = AgentPrompt::create([
        'agent_key' => 'checkout_closer',
        'type' => 'agent',
        'content' => "# CheckoutAgent\n\nContenido de la versión que corrió en el turn.",
        'version' => 7,
        'is_active' => true,
        'status' => AgentPrompt::STATUS_ACTIVE,
        'notes' => 'v7 con ajuste de fallback',
    ]);
});

it('returns a JSON payload with the prompt fields', function () {
    $this->actingAs($this->admin)
        ->getJson(route('admin.agent-prompts.view', $this->prompt))
        ->assertOk()
        ->assertJson([
            'id' => $this->prompt->id,
            'agent_key' => 'checkout_closer',
            'agent_label' => 'CheckoutAgent',
            'version' => 7,
            'status' => AgentPrompt::STATUS_ACTIVE,
            'is_active' => true,
            'notes' => 'v7 con ajuste de fallback',
            'content' => "# CheckoutAgent\n\nContenido de la versión que corrió en el turn.",
        ])
        ->assertJsonStructure(['created_at']);
});

it('requires admin auth', function () {
    $this->get(route('admin.agent-prompts.view', $this->prompt))
        ->assertRedirect('/login');

    $regular = User::factory()->create();
    $this->actingAs($regular)
        ->getJson(route('admin.agent-prompts.view', $this->prompt))
        ->assertForbidden();
});

it('returns 404 for a non-existent prompt', function () {
    $this->actingAs($this->admin)
        ->getJson('/admin/agent-prompts/view/99999')
        ->assertNotFound();
});
