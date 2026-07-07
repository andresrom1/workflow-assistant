<?php

use App\Models\AgentExecutionLog;
use App\Models\AgentPrompt;
use App\Repositories\AnalyticsRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('groups entered and completed by prompt version for a step', function () {
    $promptV1 = AgentPrompt::create([
        'agent_key' => 'coverage_preference', 'type' => 'agent', 'content' => 'v1',
        'version' => 1, 'is_active' => false, 'status' => 'archived', 'notes' => 'primera versión',
    ]);
    $promptV2 = AgentPrompt::create([
        'agent_key' => 'coverage_preference', 'type' => 'agent', 'content' => 'v2',
        'version' => 2, 'is_active' => true, 'status' => 'active', 'notes' => 'segunda versión',
    ]);

    // v1: 3 entraron, 2 completaron (step 3 = coverage_set)
    AgentExecutionLog::factory()->forStep(3)->create(['agent_prompt_id' => $promptV1->id, 'state_changes' => ['coverage_set' => true]]);
    AgentExecutionLog::factory()->forStep(3)->create(['agent_prompt_id' => $promptV1->id, 'state_changes' => ['coverage_set' => true]]);
    AgentExecutionLog::factory()->forStep(3)->create(['agent_prompt_id' => $promptV1->id, 'state_changes' => []]);

    // v2: 2 entraron, 2 completaron
    AgentExecutionLog::factory()->forStep(3)->create(['agent_prompt_id' => $promptV2->id, 'state_changes' => ['coverage_set' => true]]);
    AgentExecutionLog::factory()->forStep(3)->create(['agent_prompt_id' => $promptV2->id, 'state_changes' => ['coverage_set' => true]]);

    $repo = new AnalyticsRepository;
    $result = $repo->funnelByPromptVersion(Carbon::now()->subDay(), Carbon::now()->addDay());

    $step3 = collect($result[3])->keyBy('agent_prompt_id');

    expect($step3[$promptV1->id]['entered'])->toBe(3)
        ->and($step3[$promptV1->id]['completed'])->toBe(2)
        ->and($step3[$promptV1->id]['conversion'])->toBe(0.6667)
        ->and($step3[$promptV1->id]['version'])->toBe(1)
        ->and($step3[$promptV1->id]['notes'])->toBe('primera versión')
        ->and($step3[$promptV2->id]['entered'])->toBe(2)
        ->and($step3[$promptV2->id]['completed'])->toBe(2)
        ->and($step3[$promptV2->id]['conversion'])->toBe(1.0);
});

it('groups logs without agent_prompt_id under a null key', function () {
    AgentExecutionLog::factory()->forStep(1)->create(['agent_prompt_id' => null, 'state_changes' => ['customer_identified' => true]]);

    $repo = new AnalyticsRepository;
    $result = $repo->funnelByPromptVersion(Carbon::now()->subDay(), Carbon::now()->addDay());

    $withoutVersion = collect($result[1])->firstWhere('agent_prompt_id', null);

    expect($withoutVersion)->not->toBeNull()
        ->and($withoutVersion['version'])->toBeNull()
        ->and($withoutVersion['entered'])->toBe(1)
        ->and($withoutVersion['completed'])->toBe(1);
});

it('orders versions descending within a step', function () {
    $promptV1 = AgentPrompt::create(['agent_key' => 'quote_reception', 'type' => 'agent', 'content' => 'a', 'version' => 1, 'is_active' => false, 'status' => 'archived']);
    $promptV3 = AgentPrompt::create(['agent_key' => 'quote_reception', 'type' => 'agent', 'content' => 'c', 'version' => 3, 'is_active' => true, 'status' => 'active']);

    AgentExecutionLog::factory()->forStep(4)->create(['agent_prompt_id' => $promptV1->id]);
    AgentExecutionLog::factory()->forStep(4)->create(['agent_prompt_id' => $promptV3->id]);

    $repo = new AnalyticsRepository;
    $result = $repo->funnelByPromptVersion(Carbon::now()->subDay(), Carbon::now()->addDay());

    $versions = collect($result[4])->pluck('version')->all();

    expect($versions)->toBe([3, 1]);
});
