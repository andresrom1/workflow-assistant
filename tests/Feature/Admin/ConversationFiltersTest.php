<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('returns all conversations when no flags are selected', function () {
    Conversation::factory()->count(3)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.conversations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Conversations/Index')
            ->has('conversations.data', 3)
            ->where('filters.flags', [])
        );
});

it('filters conversations by single health flag', function () {
    $matching = Conversation::factory()->create(['flags' => ['stuck' => true]]);
    Conversation::factory()->create(['flags' => ['stuck' => false]]);
    Conversation::factory()->create(['flags' => null]);

    $this->actingAs($this->admin)
        ->get(route('admin.conversations.index', ['flags' => ['stuck']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('conversations.data', 1)
            ->where('conversations.data.0.id', $matching->id)
            ->where('filters.flags', ['stuck'])
        );
});

it('combines multiple flag filters with AND logic', function () {
    $both = Conversation::factory()->create(['flags' => ['stuck' => true, 'loops' => true]]);
    Conversation::factory()->create(['flags' => ['stuck' => true, 'loops' => false]]);
    Conversation::factory()->create(['flags' => ['stuck' => false, 'loops' => true]]);

    $this->actingAs($this->admin)
        ->get(route('admin.conversations.index', ['flags' => ['stuck', 'loops']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('conversations.data', 1)
            ->where('conversations.data.0.id', $both->id)
        );
});

it('ignores unknown flag values from the query string', function () {
    Conversation::factory()->count(2)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.conversations.index', ['flags' => ['bogus_flag', 'stuck']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.flags', ['stuck'])
        );
});

it('includes flag counts for every known flag', function () {
    Conversation::factory()->create(['flags' => ['stuck' => true]]);
    Conversation::factory()->create(['flags' => ['loops' => true, 'stuck' => true]]);

    $this->actingAs($this->admin)
        ->get(route('admin.conversations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('flag_counts.stuck', 2)
            ->where('flag_counts.loops', 1)
            ->where('flag_counts.tool_errors', 0)
            ->where('flag_counts.abandoned', 0)
            ->where('flag_counts.long', 0)
        );
});
