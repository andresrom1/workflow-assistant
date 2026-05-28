<?php

use App\Models\AgentExecutionLog;
use App\Models\AgentExecutionLogAnnotation;
use App\Models\Conversation;
use App\Models\User;
use App\Repositories\AnalyticsRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->from = Carbon::now()->subDays(7)->startOfDay();
    $this->to = Carbon::now()->endOfDay();
});

it('funnel page renders with steps data', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.analytics.funnel'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Analytics/Funnel')
            ->has('steps', 5)
            ->has('from')
            ->has('to')
        );
});

it('requires admin auth', function () {
    $this->get(route('admin.analytics.funnel'))
        ->assertRedirect('/login');

    $regular = User::factory()->create();
    $this->actingAs($regular)
        ->get(route('admin.analytics.funnel'))
        ->assertForbidden();
});

it('funnel respects date range filter', function () {
    $conversation = Conversation::factory()->create();

    // Log inside range
    $logInRange = AgentExecutionLog::factory()->forStep(1)->create([
        'conversation_id' => $conversation->id,
        'created_at' => now()->subDays(3),
    ]);

    // Log outside range
    AgentExecutionLog::factory()->forStep(1)->create([
        'conversation_id' => $conversation->id,
        'created_at' => now()->subDays(30),
    ]);

    $repo = app(AnalyticsRepository::class);
    $steps = $repo->funnelSteps(
        Carbon::now()->subDays(7)->startOfDay(),
        Carbon::now()->endOfDay()
    );

    $step1 = collect($steps)->firstWhere('step', 1);

    // Only the in-range log should count
    expect($step1['entered'])->toBe(1);
});

it('entered count reflects distinct conversations per step', function () {
    $conv1 = Conversation::factory()->create();
    $conv2 = Conversation::factory()->create();

    // Both conversations touch step 1
    AgentExecutionLog::factory()->forStep(1)->create(['conversation_id' => $conv1->id]);
    AgentExecutionLog::factory()->forStep(1)->create(['conversation_id' => $conv1->id]);
    AgentExecutionLog::factory()->forStep(1)->create(['conversation_id' => $conv2->id]);

    // Only conv1 touches step 2
    AgentExecutionLog::factory()->forStep(2)->create(['conversation_id' => $conv1->id]);

    $repo = app(AnalyticsRepository::class);
    $steps = $repo->funnelSteps($this->from, $this->to);

    $step1 = collect($steps)->firstWhere('step', 1);
    $step2 = collect($steps)->firstWhere('step', 2);

    expect($step1['entered'])->toBe(2)
        ->and($step2['entered'])->toBe(1);
});

it('completed count reflects successful state transitions', function () {
    $conversation = Conversation::factory()->create();

    // Log step 1 WITH state_change for customer_identified → completed
    AgentExecutionLog::factory()->forStep(1)->create([
        'conversation_id' => $conversation->id,
        'state_changes' => ['customer_identified' => true],
    ]);

    // Log step 2 WITHOUT state_change → not completed
    AgentExecutionLog::factory()->forStep(2)->create([
        'conversation_id' => $conversation->id,
        'state_changes' => [],
    ]);

    $repo = app(AnalyticsRepository::class);
    $steps = $repo->funnelSteps($this->from, $this->to);

    $step1 = collect($steps)->firstWhere('step', 1);
    $step2 = collect($steps)->firstWhere('step', 2);

    expect($step1['completed'])->toBe(1)
        ->and($step2['completed'])->toBe(0);
});

it('negative annotations are counted per step', function () {
    $conversation = Conversation::factory()->create();
    $log = AgentExecutionLog::factory()->forStep(2)->create([
        'conversation_id' => $conversation->id,
    ]);

    AgentExecutionLogAnnotation::create([
        'agent_execution_log_id' => $log->id,
        'user_id' => $this->admin->id,
        'verdict' => false,
        'note' => 'respuesta incorrecta',
    ]);

    $repo = app(AnalyticsRepository::class);
    $steps = $repo->funnelSteps($this->from, $this->to);

    $step2 = collect($steps)->firstWhere('step', 2);

    expect($step2['negative_annotations'])->toBe(1);
});

it('abandonment_rate is 0 when all entered also completed', function () {
    $conversation = Conversation::factory()->create();

    AgentExecutionLog::factory()->forStep(3)->create([
        'conversation_id' => $conversation->id,
        'state_changes' => ['coverage_set' => true],
    ]);

    $repo = app(AnalyticsRepository::class);
    $steps = $repo->funnelSteps($this->from, $this->to);

    $step3 = collect($steps)->firstWhere('step', 3);

    expect($step3['entered'])->toBe(1)
        ->and($step3['completed'])->toBe(1)
        ->and($step3['abandonment_rate'])->toBe(0.0);
});

it('returns all 5 steps even with no data', function () {
    $repo = app(AnalyticsRepository::class);
    $steps = $repo->funnelSteps(
        Carbon::now()->subDays(1)->startOfDay(),
        Carbon::now()->endOfDay()
    );

    expect($steps)->toHaveCount(5);

    foreach ($steps as $step) {
        expect($step['entered'])->toBe(0)
            ->and($step['completed'])->toBe(0)
            ->and($step['abandonment_rate'])->toBe(0.0)
            ->and($step['negative_annotations'])->toBe(0);
    }
});
