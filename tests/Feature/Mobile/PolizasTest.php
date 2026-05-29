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

function makePas(): User
{
    return User::factory()->create([
        'role' => UserRole::Pas,
        'name' => 'Lucía Fernández',
        'metadata' => ['matricula' => '88.241', 'phone' => '+5491156782341'],
    ]);
}

function makeCustomer(User $pas, string $email = 'tomas@example.com'): Customer
{
    return Customer::factory()->create([
        'pas_id' => $pas->id,
        'name' => 'Tomás Iglesias',
        'email' => $email,
        'dni' => '30123456',
    ]);
}

function makeVehicleRiskWithPoliza(Customer $customer, float $suma = 14_200_000): Poliza
{
    $risk = Risk::create([
        'customer_id' => $customer->id,
        'type' => RiskType::Vehicle,
        'label' => 'Toyota Corolla 2021',
        'metadata' => ['patente' => 'AE 428 LM', 'marca' => 'Toyota'],
    ]);

    return Poliza::create([
        'risk_id' => $risk->id,
        'estado' => PolizaEstado::Vigente,
        'numero' => 'POL-100',
        'company' => 'La Caja Seguros',
        'coverage' => 'Todo riesgo C',
        'coverage_detail' => 'Daños totales + parciales + robo',
        'sum_asegurada' => $suma,
        'cuota' => 78_450,
        'cuota_due' => now()->addDays(7),
        'vigencia' => now()->addMonths(6),
    ]);
}

it('requiere autenticación', function (): void {
    $this->getJson('/api/mobile/v1/polizas')
        ->assertStatus(401)
        ->assertJson(['code' => 'UNAUTHENTICATED']);
});

it('devuelve PAS + polizas propias + riesgos compartidos para una cuenta linkeada', function (): void {
    $pas = makePas();
    $customer = makeCustomer($pas);
    $poliza = makeVehicleRiskWithPoliza($customer);

    $account = MobileAccount::factory()->create([
        'email' => 'tomas@example.com',
        'customer_id' => $customer->id,
    ]);

    Sanctum::actingAs($account, ['*'], 'mobile');

    $response = $this->getJson('/api/mobile/v1/polizas')->assertOk();

    $response->assertJson([
        'pas' => ['name' => 'Lucía Fernández', 'matricula' => '88.241'],
        'polizas_propias' => [
            ['id' => $poliza->id, 'label' => 'Toyota Corolla 2021', 'company' => 'La Caja Seguros'],
        ],
        'riesgos_compartidos' => [],
    ]);
});

it('matchea Customer por email cuando no hay linking (mock laxo)', function (): void {
    $pas = makePas();
    $customer = makeCustomer($pas, 'andres@gmail.com');
    makeVehicleRiskWithPoliza($customer);

    // Cuenta SIN customer_id, pero mismo email que el Customer.
    $account = MobileAccount::factory()->create([
        'email' => 'andres@gmail.com',
        'customer_id' => null,
    ]);

    Sanctum::actingAs($account, ['*'], 'mobile');

    $this->getJson('/api/mobile/v1/polizas')
        ->assertOk()
        ->assertJsonCount(1, 'polizas_propias');
});

it('ordena pólizas propias por sum_asegurada descendente', function (): void {
    $pas = makePas();
    $customer = makeCustomer($pas);
    $barata = makeVehicleRiskWithPoliza($customer, 5_000_000);
    $cara = makeVehicleRiskWithPoliza($customer, 20_000_000);

    $account = MobileAccount::factory()->create([
        'email' => $customer->email,
        'customer_id' => $customer->id,
    ]);
    Sanctum::actingAs($account, ['*'], 'mobile');

    $ids = $this->getJson('/api/mobile/v1/polizas')->json('polizas_propias.*.id');
    expect($ids)->toBe([$cara->id, $barata->id]);
});

it('muestra riesgos compartidos por email aunque no haya Customer propio (Pattern C)', function (): void {
    $pas = makePas();
    $tomas = makeCustomer($pas, 'tomas@example.com');
    $polizaTomas = makeVehicleRiskWithPoliza($tomas);

    $tomasAccount = MobileAccount::factory()->create([
        'email' => 'tomas@example.com',
        'customer_id' => $tomas->id,
    ]);

    SharedRisk::create([
        'risk_id' => $polizaTomas->risk_id,
        'shared_with_email' => 'andres@gmail.com',
        'invited_by_mobile_account_id' => $tomasAccount->id,
        'token' => Str::random(48),
        'expires_at' => now()->addMonths(6),
        'accepted_at' => now()->subDays(5),
    ]);

    // Andrés: MobileAccount SIN Customer propio
    $andres = MobileAccount::factory()->create([
        'email' => 'andres@gmail.com',
        'customer_id' => null,
    ]);
    Sanctum::actingAs($andres, ['*'], 'mobile');

    $response = $this->getJson('/api/mobile/v1/polizas')->assertOk();

    $response->assertJsonCount(0, 'polizas_propias');
    $response->assertJsonCount(1, 'riesgos_compartidos');
    $response->assertJson([
        'riesgos_compartidos' => [
            ['titular' => 'Tomás Iglesias', 'pas' => ['name' => 'Lucía Fernández']],
        ],
    ]);
});

it('no incluye riesgos compartidos revocados ni expirados sin aceptar', function (): void {
    $pas = makePas();
    $tomas = makeCustomer($pas);
    $poliza = makeVehicleRiskWithPoliza($tomas);
    $tomasAccount = MobileAccount::factory()->create([
        'email' => $tomas->email,
        'customer_id' => $tomas->id,
    ]);

    // Revocado
    SharedRisk::create([
        'risk_id' => $poliza->risk_id,
        'shared_with_email' => 'andres@gmail.com',
        'invited_by_mobile_account_id' => $tomasAccount->id,
        'token' => Str::random(48),
        'expires_at' => now()->addMonths(6),
        'accepted_at' => now()->subDays(10),
        'revoked_at' => now()->subDays(1),
    ]);
    // Expirado sin aceptar
    SharedRisk::create([
        'risk_id' => $poliza->risk_id,
        'shared_with_email' => 'andres@gmail.com',
        'invited_by_mobile_account_id' => $tomasAccount->id,
        'token' => Str::random(48),
        'expires_at' => now()->subDays(1),
        'accepted_at' => null,
    ]);

    $andres = MobileAccount::factory()->create(['email' => 'andres@gmail.com', 'customer_id' => null]);
    Sanctum::actingAs($andres, ['*'], 'mobile');

    $this->getJson('/api/mobile/v1/polizas')
        ->assertOk()
        ->assertJsonCount(0, 'riesgos_compartidos');
});

it('GET /polizas/{id} devuelve detalle si el risk es propio', function (): void {
    $pas = makePas();
    $customer = makeCustomer($pas);
    $poliza = makeVehicleRiskWithPoliza($customer);
    $account = MobileAccount::factory()->create(['email' => $customer->email, 'customer_id' => $customer->id]);
    Sanctum::actingAs($account, ['*'], 'mobile');

    $this->getJson("/api/mobile/v1/polizas/{$poliza->id}")
        ->assertOk()
        ->assertJson(['id' => $poliza->id, 'company' => 'La Caja Seguros']);
});

it('GET /polizas/{id} devuelve 403 si la póliza no es del usuario ni compartida', function (): void {
    $pas = makePas();
    $otro = makeCustomer($pas, 'otro@example.com');
    $poliza = makeVehicleRiskWithPoliza($otro);

    $account = MobileAccount::factory()->create(['email' => 'andres@gmail.com', 'customer_id' => null]);
    Sanctum::actingAs($account, ['*'], 'mobile');

    $this->getJson("/api/mobile/v1/polizas/{$poliza->id}")
        ->assertStatus(403)
        ->assertJson(['code' => 'POLIZA_FORBIDDEN']);
});

it('GET /polizas/{id} devuelve 404 si la póliza no existe', function (): void {
    $account = MobileAccount::factory()->create();
    Sanctum::actingAs($account, ['*'], 'mobile');

    $this->getJson('/api/mobile/v1/polizas/99999')
        ->assertStatus(404)
        ->assertJson(['code' => 'POLIZA_NOT_FOUND']);
});
