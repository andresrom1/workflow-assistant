<?php

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('siembra al admin como PAS con perfil completo (matrícula + teléfono)', function (): void {
    $this->seed(AdminUserSeeder::class);

    $admin = User::where('email', AdminUserSeeder::EMAIL)->firstOrFail();

    expect($admin->role)->toBe(UserRole::Admin)
        ->and($admin->isPas())->toBeTrue()
        ->and($admin->pasPhone())->not->toBeNull()
        ->and($admin->pasMatricula())->not->toBeNull();
});

it('asigna el admin como PAS a TODOS los customers seed', function (): void {
    $this->seed(AdminUserSeeder::class);
    $this->seed(CustomerSeeder::class);

    $adminId = User::where('email', AdminUserSeeder::EMAIL)->value('id');

    expect(Customer::count())->toBeGreaterThan(0)
        ->and(Customer::whereNull('pas_id')->count())->toBe(0)
        ->and(Customer::where('pas_id', '!=', $adminId)->count())->toBe(0);
});

it('hace backfill de customers preexistentes sin PAS', function (): void {
    $huerfano = Customer::factory()->create(['pas_id' => null]);

    $this->seed(AdminUserSeeder::class);
    $this->seed(CustomerSeeder::class);

    $adminId = User::where('email', AdminUserSeeder::EMAIL)->value('id');

    expect($huerfano->refresh()->pas_id)->toBe($adminId);
});

it('es idempotente: rellena la metadata de un admin preexistente sin perfil de PAS', function (): void {
    // Simula el caso GCP: el admin ya existía (logueó antes del seed) sin teléfono.
    $existente = User::factory()->create([
        'email' => AdminUserSeeder::EMAIL,
        'role' => UserRole::Admin,
        'metadata' => [],
    ]);

    $this->seed(AdminUserSeeder::class);

    expect(User::where('email', AdminUserSeeder::EMAIL)->count())->toBe(1)
        ->and($existente->refresh()->pasPhone())->not->toBeNull()
        ->and($existente->pasMatricula())->not->toBeNull();
});
