<?php

use App\AI\Tools\SiniestroGuidanceTool;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

function siniestroRequest(): Request
{
    return new Request(['situacion' => 'Choqué el auto']);
}

it('returns the customer own PAS with phone when available', function () {
    $pas = User::factory()->create([
        'name' => 'Andrés Romero',
        'role' => UserRole::Pas,
        'metadata' => ['phone' => '5493511234567'],
    ]);
    $customer = Customer::factory()->create(['pas_id' => $pas->id]);
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $result = json_decode((new SiniestroGuidanceTool($conversation))->handle(siniestroRequest()), true);

    expect($result['pas'])->toBe(['nombre' => 'Andrés Romero', 'telefono' => '5493511234567'])
        ->and($result['nota'])->toBeNull()
        ->and($result['indicaciones'])->toBeArray()->not->toBeEmpty();
});

it('falls back to the default PAS when the customer has none assigned', function () {
    $defaultPas = User::factory()->create([
        'name' => 'PAS Default',
        'role' => UserRole::Pas,
        'email' => 'default-pas@mango.test',
        'metadata' => ['phone' => '5493510000000'],
    ]);
    config(['mango.default_pas_email' => 'default-pas@mango.test']);

    $customer = Customer::factory()->create(['pas_id' => null]);
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $result = json_decode((new SiniestroGuidanceTool($conversation))->handle(siniestroRequest()), true);

    expect($result['pas'])->toBe(['nombre' => 'PAS Default', 'telefono' => '5493510000000']);
});

it('returns pas null with a note when there is no phone on file', function () {
    $pas = User::factory()->create(['role' => UserRole::Pas, 'metadata' => []]);
    $customer = Customer::factory()->create(['pas_id' => $pas->id]);
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $result = json_decode((new SiniestroGuidanceTool($conversation))->handle(siniestroRequest()), true);

    expect($result['pas'])->toBeNull()
        ->and($result['nota'])->not->toBeNull();
});

it('returns pas null with a note when there is no PAS at all', function () {
    config(['mango.default_pas_email' => null]);
    $customer = Customer::factory()->create(['pas_id' => null]);
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $result = json_decode((new SiniestroGuidanceTool($conversation))->handle(siniestroRequest()), true);

    expect($result['pas'])->toBeNull()
        ->and($result['nota'])->not->toBeNull();
});
