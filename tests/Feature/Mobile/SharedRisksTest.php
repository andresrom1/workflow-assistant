<?php

use App\Enums\PolizaEstado;
use App\Enums\RiskType;
use App\Models\Customer;
use App\Models\MobileAccount;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\SharedRisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function setupTitular(): array
{
    $titular = Customer::factory()->create(['email' => 'titular@example.com']);
    $account = MobileAccount::factory()->create([
        'email' => 'titular@example.com',
        'customer_id' => $titular->id,
    ]);

    $risk = Risk::create([
        'customer_id' => $titular->id,
        'type' => RiskType::Vehicle,
        'label' => 'Toyota Corolla 2021',
        'metadata' => ['patente' => 'AE 428 LM'],
    ]);

    $poliza = Poliza::create([
        'risk_id' => $risk->id,
        'estado' => PolizaEstado::Vigente,
        'company' => 'X', 'coverage' => 'Y', 'sum_asegurada' => 14_000_000,
        'vigencia' => now()->addMonths(6),
    ]);

    Sanctum::actingAs($account, ['*'], 'mobile');

    return [$account, $titular, $risk, $poliza];
}

it('requiere autenticación', function (): void {
    $this->getJson('/api/mobile/v1/shared-risks/1')
        ->assertStatus(401)
        ->assertJson(['code' => 'UNAUTHENTICATED']);
});

it('lista shared_risks activos de una póliza propia', function (): void {
    [$account, , $risk, $poliza] = setupTitular();

    SharedRisk::create([
        'risk_id' => $risk->id,
        'shared_with_email' => 'amigo@example.com',
        'invited_by_mobile_account_id' => $account->id,
        'token' => Str::random(48),
        'expires_at' => now()->addMonths(1),
        'accepted_at' => now()->subDays(2),
    ]);
    // Uno revocado, no debe aparecer
    SharedRisk::create([
        'risk_id' => $risk->id,
        'shared_with_email' => 'ex@example.com',
        'invited_by_mobile_account_id' => $account->id,
        'token' => Str::random(48),
        'expires_at' => now()->addMonths(1),
        'revoked_at' => now()->subDay(),
    ]);

    $this->getJson("/api/mobile/v1/shared-risks/{$poliza->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJson(['data' => [['shared_with_email' => 'amigo@example.com', 'status' => 'aceptado']]]);
});

it('rechaza listar shared_risks de una póliza ajena', function (): void {
    setupTitular();
    $otroCust = Customer::factory()->create();
    $otroRisk = Risk::create([
        'customer_id' => $otroCust->id, 'type' => RiskType::Vehicle, 'label' => 'X', 'metadata' => [],
    ]);
    $otraPoliza = Poliza::create([
        'risk_id' => $otroRisk->id, 'estado' => PolizaEstado::Vigente,
        'company' => 'X', 'coverage' => 'Y', 'sum_asegurada' => 1_000_000,
        'vigencia' => now()->addMonths(6),
    ]);

    $this->getJson("/api/mobile/v1/shared-risks/{$otraPoliza->id}")
        ->assertStatus(403)
        ->assertJson(['code' => 'SHARED_RISK_NOT_OWNER']);
});

it('invita por email y devuelve token + status pendiente', function (): void {
    [, , , $poliza] = setupTitular();

    $this->postJson('/api/mobile/v1/shared-risks/invitar', [
        'poliza_id' => $poliza->id,
        'shared_with_email' => 'nuevo@example.com',
    ])
        ->assertStatus(201)
        ->assertJson(['shared_with_email' => 'nuevo@example.com', 'status' => 'pendiente'])
        ->assertJsonStructure(['id', 'invite_url', 'expires_at']);
});

it('guarda y devuelve el nombre del invitado', function (): void {
    [, , $risk, $poliza] = setupTitular();

    $this->postJson('/api/mobile/v1/shared-risks/invitar', [
        'poliza_id' => $poliza->id,
        'shared_with_email' => 'pareja@example.com',
        'name' => 'Sofía',
    ])
        ->assertStatus(201)
        ->assertJson(['name' => 'Sofía', 'shared_with_email' => 'pareja@example.com']);

    expect(SharedRisk::where('risk_id', $risk->id)->first()->name)->toBe('Sofía');
});

it('es idempotente: invitar de nuevo al mismo email devuelve la existente', function (): void {
    [$account, , $risk, $poliza] = setupTitular();

    $first = SharedRisk::create([
        'risk_id' => $risk->id,
        'shared_with_email' => 'nuevo@example.com',
        'invited_by_mobile_account_id' => $account->id,
        'token' => Str::random(48),
        'expires_at' => now()->addMonths(1),
    ]);

    $this->postJson('/api/mobile/v1/shared-risks/invitar', [
        'poliza_id' => $poliza->id,
        'shared_with_email' => 'nuevo@example.com',
    ])
        ->assertOk()
        ->assertJson(['id' => $first->id]);

    expect(SharedRisk::count())->toBe(1);
});

it('bloquea al llegar al máximo (2) conductores activos', function (): void {
    [$account, , $risk, $poliza] = setupTitular();

    foreach (['a@example.com', 'b@example.com'] as $email) {
        SharedRisk::create([
            'risk_id' => $risk->id,
            'shared_with_email' => $email,
            'invited_by_mobile_account_id' => $account->id,
            'token' => Str::random(48),
            'expires_at' => now()->addMonths(1),
            'accepted_at' => now()->subDay(),
        ]);
    }

    $this->postJson('/api/mobile/v1/shared-risks/invitar', [
        'poliza_id' => $poliza->id,
        'shared_with_email' => 'c@example.com',
    ])->assertStatus(422)->assertJson(['code' => 'SHARED_RISK_LIMIT_REACHED']);
});

it('revoca un shared_risk propio', function (): void {
    [$account, , $risk, $poliza] = setupTitular();
    $sr = SharedRisk::create([
        'risk_id' => $risk->id,
        'shared_with_email' => 'a@example.com',
        'invited_by_mobile_account_id' => $account->id,
        'token' => Str::random(48),
        'expires_at' => now()->addMonths(1),
        'accepted_at' => now()->subDay(),
    ]);

    $this->deleteJson("/api/mobile/v1/shared-risks/{$poliza->id}/{$sr->id}")->assertStatus(204);

    expect($sr->fresh()->revoked_at)->not->toBeNull();
});

it('DELETE devuelve 404 si la invitación no existe en esa póliza', function (): void {
    [, , , $poliza] = setupTitular();

    $this->deleteJson("/api/mobile/v1/shared-risks/{$poliza->id}/99999")
        ->assertStatus(404)
        ->assertJson(['code' => 'SHARED_RISK_NOT_FOUND']);
});

it('el invitado se auto-revoca de un vehículo compartido (Quitar vehículo)', function (): void {
    [$titularAccount, , $risk] = setupTitular();

    $sr = SharedRisk::create([
        'risk_id' => $risk->id,
        'shared_with_email' => 'invitado@example.com',
        'invited_by_mobile_account_id' => $titularAccount->id,
        'token' => Str::random(48),
        'expires_at' => now()->addMonths(1),
    ]);

    // El invitado se autentica y se desacopla por risk_id.
    $invitado = MobileAccount::factory()->create(['email' => 'invitado@example.com']);
    Sanctum::actingAs($invitado, ['*'], 'mobile');

    $this->deleteJson("/api/mobile/v1/shared-risks/mias/{$risk->id}")->assertStatus(204);

    expect($sr->fresh()->revoked_at)->not->toBeNull();
});

it('auto-revocación devuelve 404 si no hay invitación para mi email', function (): void {
    [$titularAccount, , $risk] = setupTitular();

    SharedRisk::create([
        'risk_id' => $risk->id,
        'shared_with_email' => 'otro@example.com',
        'invited_by_mobile_account_id' => $titularAccount->id,
        'token' => Str::random(48),
        'expires_at' => now()->addMonths(1),
    ]);

    // Autenticado con un email distinto al de la invitación.
    $ajeno = MobileAccount::factory()->create(['email' => 'ajeno@example.com']);
    Sanctum::actingAs($ajeno, ['*'], 'mobile');

    $this->deleteJson("/api/mobile/v1/shared-risks/mias/{$risk->id}")
        ->assertStatus(404)
        ->assertJson(['code' => 'SHARED_RISK_NOT_FOUND']);
});
