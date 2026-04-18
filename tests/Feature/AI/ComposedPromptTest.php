<?php

use App\Models\AgentPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    AgentPrompt::create([
        'agent_key' => 'shared_style',
        'type' => 'shared',
        'content' => 'STYLE_BLOCK',
        'version' => 1,
        'is_active' => true,
    ]);

    AgentPrompt::create([
        'agent_key' => 'shared_grounding',
        'type' => 'shared',
        'content' => 'GROUNDING_BLOCK',
        'version' => 1,
        'is_active' => true,
    ]);

    AgentPrompt::create([
        'agent_key' => 'checkout_closer',
        'type' => 'agent',
        'content' => 'AGENT_BLOCK',
        'version' => 1,
        'is_active' => true,
    ]);
});

it('concatenates shared blocks before the agent content in the declared order', function () {
    $composed = AgentPrompt::compose('checkout_closer', ['shared_style', 'shared_grounding']);

    expect($composed)->toBe("STYLE_BLOCK\n\nGROUNDING_BLOCK\n\nAGENT_BLOCK");
});

it('filters out keys that do not exist', function () {
    $composed = AgentPrompt::compose('checkout_closer', ['shared_style', 'nonexistent', 'shared_grounding']);

    expect($composed)->toBe("STYLE_BLOCK\n\nGROUNDING_BLOCK\n\nAGENT_BLOCK");
});

it('returns only the agent content when no shared keys are passed', function () {
    expect(AgentPrompt::compose('checkout_closer'))->toBe('AGENT_BLOCK');
});

it('returns empty string when no blocks match', function () {
    expect(AgentPrompt::compose('nonexistent', ['also_missing']))->toBe('');
});

it('reflects updated shared block content after activating a new version', function () {
    $firstComposed = AgentPrompt::compose('checkout_closer', ['shared_style']);
    expect($firstComposed)->toContain('STYLE_BLOCK');

    $newStyle = AgentPrompt::create([
        'agent_key' => 'shared_style',
        'type' => 'shared',
        'content' => 'NEW_STYLE_BLOCK',
        'version' => 2,
        'is_active' => false,
    ]);

    $newStyle->activate();

    $recomposed = AgentPrompt::compose('checkout_closer', ['shared_style']);

    expect($recomposed)->toBe("NEW_STYLE_BLOCK\n\nAGENT_BLOCK");
});
