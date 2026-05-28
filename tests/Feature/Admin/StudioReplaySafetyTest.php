<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Tools\CheckoutTool;
use App\AI\Tools\CoveragePreferenceTool;
use App\AI\Tools\GetQuoteTool;
use App\AI\Tools\IdentifyCustomerTool;
use App\AI\Tools\IdentifyVehicleTool;
use App\Models\AgentExecutionLog;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('mock tools return canned payload when replay flag is bound', function () {
    $conversation = Conversation::factory()->create();

    app()->instance('ai.replay_mode', true);

    try {
        $tools = [
            new IdentifyCustomerTool(app(WhatsAppAdapter::class), $conversation),
            new IdentifyVehicleTool(app(WhatsAppAdapter::class), $conversation),
            new CoveragePreferenceTool(app(WhatsAppAdapter::class), $conversation),
            new GetQuoteTool(app(WhatsAppAdapter::class), $conversation),
            new CheckoutTool(app(WhatsAppAdapter::class), $conversation),
        ];

        foreach ($tools as $tool) {
            $req = new Request(['dummy' => 'value']);
            $result = $tool->handle($req);
            $decoded = json_decode($result, true);
            expect($decoded)->toBeArray()
                ->and($decoded['mock'] ?? false)->toBeTrue();
        }
    } finally {
        app()->forgetInstance('ai.replay_mode');
    }
});

it('mock tools do not mutate conversation state during replay', function () {
    $conversation = Conversation::factory()->create();
    $stateBefore = $conversation->fresh()->metadata;

    app()->instance('ai.replay_mode', true);

    try {
        $tool = new GetQuoteTool(app(WhatsAppAdapter::class), $conversation);
        $tool->handle(new Request(['quoteId' => 42]));
    } finally {
        app()->forgetInstance('ai.replay_mode');
    }

    $stateAfter = $conversation->fresh()->metadata;
    expect($stateAfter)->toEqual($stateBefore);
});

it('studio endpoint requires admin auth', function () {
    $conversation = Conversation::factory()->create();
    $log = AgentExecutionLog::factory()->forStep(1)->create([
        'conversation_id' => $conversation->id,
    ]);

    $this->get(route('admin.studio.show', $log))
        ->assertRedirect('/login');
});

it('studio show renders for admin with inertia page', function () {
    $conversation = Conversation::factory()->create();
    $log = AgentExecutionLog::factory()->forStep(1)->create([
        'conversation_id' => $conversation->id,
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.studio.show', $log))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Studio/Reevaluate')
            ->where('log.id', $log->id)
            ->where('log.agent_key', 'customer_identifier')
            ->has('messages')
        );
});

it('studio reevaluate endpoint validates required fields', function () {
    $this->actingAs($this->admin)
        ->postJson(route('admin.studio.reevaluate'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'agent_key', 'conversation_id', 'agent_execution_log_id', 'draft_instructions',
        ]);
});

it('studio reevaluate rejects unknown agent_key with 422', function () {
    Bus::fake();
    $conversation = Conversation::factory()->create();
    $log = AgentExecutionLog::factory()->forStep(1)->create([
        'conversation_id' => $conversation->id,
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('admin.studio.reevaluate'), [
            'agent_key' => 'no_such_agent',
            'conversation_id' => $conversation->id,
            'agent_execution_log_id' => $log->id,
            'draft_instructions' => 'dummy instructions',
        ])
        ->assertStatus(422);
});
