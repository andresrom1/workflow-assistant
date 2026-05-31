<?php

use App\Enums\PolizaEstado;
use App\Enums\RiskType;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\MobileAccount;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\SharedRisk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('requiere autenticación', function (): void {
    $this->postJson('/api/mobile/v1/siniestro')
        ->assertStatus(401)
        ->assertJson(['code' => 'UNAUTHENTICATED']);
});

it('notifica al PAS del titular cuando hay Customer linkeado', function (): void {
    $pas = User::factory()->create([
        'role' => UserRole::Pas,
        'name' => 'Lucía Fernández',
        'metadata' => ['phone' => '+5491156782341'],
    ]);
    $customer = Customer::factory()->create([
        'pas_id' => $pas->id,
        'email' => 'andres@gmail.com',
    ]);
    $account = MobileAccount::factory()->create([
        'email' => 'andres@gmail.com',
        'customer_id' => $customer->id,
    ]);
    Sanctum::actingAs($account, ['*'], 'mobile');

    $this->postJson('/api/mobile/v1/siniestro')
        ->assertOk()
        ->assertJson([
            'pas' => ['name' => 'Lucía Fernández', 'phone' => '+5491156782341'],
        ])
        ->assertJsonStructure(['notified_at']);
});

it('cae al fallback (PAS del titular del shared_risk de mayor sum_asegurada)', function (): void {
    // PAS de Tomás (titular del shared)
    $pasTomas = User::factory()->create([
        'role' => UserRole::Pas,
        'name' => 'Jorge Rivas',
        'metadata' => ['phone' => '+5491187654321'],
    ]);
    $tomas = Customer::factory()->create(['pas_id' => $pasTomas->id, 'email' => 'tomas@example.com']);
    $tomasAccount = MobileAccount::factory()->create([
        'email' => 'tomas@example.com',
        'customer_id' => $tomas->id,
    ]);

    // Risk compartido con Andrés, suma 14M
    $risk1 = Risk::create([
        'customer_id' => $tomas->id,
        'type' => RiskType::Vehicle,
        'label' => 'Honda Civic 2022',
        'metadata' => ['patente' => 'AG 112 PK'],
    ]);
    Poliza::create([
        'risk_id' => $risk1->id,
        'estado' => PolizaEstado::Vigente,
        'company' => 'X', 'coverage' => 'Y', 'sum_asegurada' => 14_000_000,
        'vigencia' => now()->addMonths(6),
    ]);
    SharedRisk::create([
        'risk_id' => $risk1->id,
        'shared_with_email' => 'andres@gmail.com',
        'invited_by_mobile_account_id' => $tomasAccount->id,
        'token' => Str::random(48),
        'expires_at' => now()->addMonths(6),
        'accepted_at' => now()->subDays(5),
    ]);

    // Risk de otro titular (PAS distinto) con suma menor — NO debería ganar
    $pasOtro = User::factory()->create(['role' => UserRole::Pas, 'name' => 'Otro PAS']);
    $otro = Customer::factory()->create(['pas_id' => $pasOtro->id, 'email' => 'otro@example.com']);
    $otroAccount = MobileAccount::factory()->create(['email' => 'otro@example.com', 'customer_id' => $otro->id]);
    $risk2 = Risk::create([
        'customer_id' => $otro->id,
        'type' => RiskType::Vehicle,
        'label' => 'Gol Trend',
        'metadata' => [],
    ]);
    Poliza::create([
        'risk_id' => $risk2->id,
        'estado' => PolizaEstado::Vigente,
        'company' => 'X', 'coverage' => 'Y', 'sum_asegurada' => 5_000_000,
        'vigencia' => now()->addMonths(6),
    ]);
    SharedRisk::create([
        'risk_id' => $risk2->id,
        'shared_with_email' => 'andres@gmail.com',
        'invited_by_mobile_account_id' => $otroAccount->id,
        'token' => Str::random(48),
        'expires_at' => now()->addMonths(6),
        'accepted_at' => now()->subDays(5),
    ]);

    // Andrés sin Customer propio
    $andres = MobileAccount::factory()->create(['email' => 'andres@gmail.com', 'customer_id' => null]);
    Sanctum::actingAs($andres, ['*'], 'mobile');

    $this->postJson('/api/mobile/v1/siniestro')
        ->assertOk()
        ->assertJson(['pas' => ['name' => 'Jorge Rivas']]); // titular del shared con mayor suma
});

it('tier 2 funciona con invitación pendiente (sin accepted_at)', function (): void {
    $pasTomas = User::factory()->create([
        'role' => UserRole::Pas,
        'name' => 'Jorge Rivas',
        'metadata' => ['phone' => '+5491187654321'],
    ]);
    $tomas = Customer::factory()->create(['pas_id' => $pasTomas->id, 'email' => 'tomas@example.com']);
    $tomasAccount = MobileAccount::factory()->create([
        'email' => 'tomas@example.com',
        'customer_id' => $tomas->id,
    ]);

    $risk = Risk::create([
        'customer_id' => $tomas->id,
        'type' => RiskType::Vehicle,
        'label' => 'Honda Civic 2022',
        'metadata' => ['patente' => 'AG 112 PK'],
    ]);
    Poliza::create([
        'risk_id' => $risk->id,
        'estado' => PolizaEstado::Vigente,
        'company' => 'X', 'coverage' => 'Y', 'sum_asegurada' => 14_000_000,
        'vigencia' => now()->addMonths(6),
    ]);
    // Invitación pendiente: nunca se aceptó, pero es accesible (no revocada/vencida).
    SharedRisk::create([
        'risk_id' => $risk->id,
        'shared_with_email' => 'andres@gmail.com',
        'invited_by_mobile_account_id' => $tomasAccount->id,
        'token' => Str::random(48),
        'expires_at' => now()->addMonths(6),
        'accepted_at' => null,
    ]);

    $andres = MobileAccount::factory()->create(['email' => 'andres@gmail.com', 'customer_id' => null]);
    Sanctum::actingAs($andres, ['*'], 'mobile');

    $this->postJson('/api/mobile/v1/siniestro')
        ->assertOk()
        ->assertJson(['pas' => ['name' => 'Jorge Rivas']]);
});

it('cae al PAS por default cuando no hay tier 1 ni 2', function (): void {
    config(['mango.default_pas_email' => 'default@mango.com']);
    User::factory()->create([
        'role' => UserRole::Pas,
        'name' => 'Andrés Romero',
        'email' => 'default@mango.com',
        'metadata' => ['phone' => '+5491100000000'],
    ]);

    $account = MobileAccount::factory()->create([
        'email' => 'huerfano@example.com',
        'customer_id' => null,
    ]);
    Sanctum::actingAs($account, ['*'], 'mobile');

    $this->postJson('/api/mobile/v1/siniestro')
        ->assertOk()
        ->assertJson(['pas' => ['name' => 'Andrés Romero']]);
});

it('devuelve 422 NO_PAS_ASSIGNED si no hay PAS resoluble ni default', function (): void {
    config(['mango.default_pas_email' => null]);

    $account = MobileAccount::factory()->create([
        'email' => 'nadie@example.com',
        'customer_id' => null,
    ]);
    Sanctum::actingAs($account, ['*'], 'mobile');

    $this->postJson('/api/mobile/v1/siniestro')
        ->assertStatus(422)
        ->assertJson(['code' => 'NO_PAS_ASSIGNED']);
});
