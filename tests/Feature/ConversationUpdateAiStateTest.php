<?php

use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resets turns_in_current_step to zero when a flag flips false to true', function () {
    $c = Conversation::factory()->create(['turns_in_current_step' => 4]);

    $c->updateAiState(['customer_identified' => true]);

    expect($c->fresh()->turns_in_current_step)->toBe(0);
});

it('does not reset turns_in_current_step when flags remain unchanged', function () {
    $c = Conversation::factory()->create([
        'turns_in_current_step' => 3,
        'metadata' => ['ai_state' => ['customer_identified' => true]],
    ]);

    $c->updateAiState(['customer_identified' => true]);

    expect($c->fresh()->turns_in_current_step)->toBe(3);
});

it('does not reset counter when flag flips true to false', function () {
    $c = Conversation::factory()->create([
        'turns_in_current_step' => 2,
        'metadata' => ['ai_state' => ['customer_identified' => true]],
    ]);

    $c->updateAiState(['customer_identified' => false]);

    expect($c->fresh()->turns_in_current_step)->toBe(2);
});
