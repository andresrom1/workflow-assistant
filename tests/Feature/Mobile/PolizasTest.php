<?php

use App\Enums\PolizaEstado;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\MobileAccount;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\SharedRisk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
    $risk = Risk::factory()->create([
        'customer_id' => $customer->id,
        'label' => 'Toyota Corolla 2021',
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
            [
                'id' => $poliza->id,
                'label' => 'Toyota Corolla 2021',
                'company' => 'La Caja Seguros',
                'coverage_detail' => 'Daños totales + parciales + robo',
                'sum_asegurada' => '14200000.00',
                'cuota' => '78450.00',
            ],
        ],
        'riesgos_compartidos' => [],
    ]);

    // Visred no expone cartera: vigencia/cuota_due no tienen fuente → no se exponen.
    $response->assertJsonMissingPath('polizas_propias.0.vigencia')
        ->assertJsonMissingPath('polizas_propias.0.cuota_due');
});

it('resuelve el tomador propio por email (identidad = email OAuth)', function (): void {
    $pas = makePas();
    $customer = makeCustomer($pas, 'andres@gmail.com');
    makeVehicleRiskWithPoliza($customer);

    // La identidad es el email verificado por OAuth: matchea el Customer por email.
    $account = MobileAccount::factory()->create(['email' => 'andres@gmail.com']);

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

it('muestra un riesgo compartido pendiente (sin aceptar) — modelo sin aceptación', function (): void {
    $pas = makePas();
    $tomas = makeCustomer($pas);
    $polizaTomas = makeVehicleRiskWithPoliza($tomas);
    $tomasAccount = MobileAccount::factory()->create([
        'email' => 'tomas@example.com',
        'customer_id' => $tomas->id,
    ]);

    // Invitación pendiente: nunca se "aceptó", pero no está revocada ni vencida.
    SharedRisk::create([
        'risk_id' => $polizaTomas->risk_id,
        'shared_with_email' => 'andres@gmail.com',
        'invited_by_mobile_account_id' => $tomasAccount->id,
        'token' => Str::random(48),
        'expires_at' => now()->addMonths(6),
        'accepted_at' => null,
    ]);

    $andres = MobileAccount::factory()->create(['email' => 'andres@gmail.com', 'customer_id' => null]);
    Sanctum::actingAs($andres, ['*'], 'mobile');

    $this->getJson('/api/mobile/v1/polizas')
        ->assertOk()
        ->assertJsonCount(1, 'riesgos_compartidos')
        ->assertJson(['riesgos_compartidos' => [['titular' => 'Tomás Iglesias']]]);
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

it('expone tiene_documentos en las pólizas propias', function (): void {
    $pas = makePas();
    $customer = makeCustomer($pas);
    $conDoc = makeVehicleRiskWithPoliza($customer, 20_000_000);
    $sinDoc = makeVehicleRiskWithPoliza($customer, 5_000_000);

    PolicyDocument::create([
        'poliza_id' => $conDoc->id,
        'kind' => 'poliza',
        'storage_path' => "policy-documents/{$conDoc->id}/poliza.pdf",
        'source' => 'admin_upload',
        'visible_to_client' => true,
        'captured_at' => now(),
    ]);

    $account = MobileAccount::factory()->create(['email' => $customer->email, 'customer_id' => $customer->id]);
    Sanctum::actingAs($account, ['*'], 'mobile');

    // Orden por sum_asegurada desc: el de 20M (con doc) primero, el de 5M (sin doc) después.
    $this->getJson('/api/mobile/v1/polizas')
        ->assertOk()
        ->assertJson(['polizas_propias' => [
            ['id' => $conDoc->id, 'tiene_documentos' => true],
            ['id' => $sinDoc->id, 'tiene_documentos' => false],
        ]]);
});

it('GET /polizas/{id}/documentos entrega TODOS los documentos de la póliza vigente', function (): void {
    Storage::fake('r2');
    Storage::disk('r2')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration): string => "https://signed.test/{$path}"
    );

    $pas = makePas();
    $customer = makeCustomer($pas);
    $poliza = makeVehicleRiskWithPoliza($customer);

    PolicyDocument::create([
        'poliza_id' => $poliza->id,
        'kind' => 'poliza',
        'storage_path' => "policy-documents/{$poliza->id}/poliza.pdf",
        'source' => 'visred_emission',
        'visible_to_client' => true,
        'captured_at' => now(),
    ]);
    // Regla "todo lo de la vigente": aunque tenga visible_to_client=false (legacy), se
    // entrega igual porque la póliza está vigente. La visibilidad por documento se retiró.
    PolicyDocument::create([
        'poliza_id' => $poliza->id,
        'kind' => 'endoso',
        'storage_path' => "policy-documents/{$poliza->id}/endoso.pdf",
        'source' => 'admin_upload',
        'visible_to_client' => false,
    ]);

    $account = MobileAccount::factory()->create(['email' => $customer->email, 'customer_id' => $customer->id]);
    Sanctum::actingAs($account, ['*'], 'mobile');

    $this->getJson("/api/mobile/v1/polizas/{$poliza->id}/documentos")
        ->assertOk()
        ->assertJsonCount(2, 'documentos')
        // Orden por kind: 'endoso' antes que 'poliza'.
        ->assertJson(['documentos' => [
            ['kind' => 'endoso', 'url' => "https://signed.test/policy-documents/{$poliza->id}/endoso.pdf"],
            ['kind' => 'poliza', 'url' => "https://signed.test/policy-documents/{$poliza->id}/poliza.pdf"],
        ]]);
});

it('GET /polizas/{id}/documentos no entrega documentos de una póliza vencida', function (): void {
    Storage::fake('r2');
    Storage::disk('r2')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration): string => "https://signed.test/{$path}"
    );

    $pas = makePas();
    $customer = makeCustomer($pas);
    $poliza = makeVehicleRiskWithPoliza($customer);
    $poliza->update(['estado' => PolizaEstado::Vencida]);

    PolicyDocument::create([
        'poliza_id' => $poliza->id,
        'kind' => 'poliza',
        'storage_path' => "policy-documents/{$poliza->id}/poliza.pdf",
        'source' => 'admin_upload',
        'visible_to_client' => true,
        'captured_at' => now(),
    ]);

    $account = MobileAccount::factory()->create(['email' => $customer->email, 'customer_id' => $customer->id]);
    Sanctum::actingAs($account, ['*'], 'mobile');

    // La documentación de una póliza vencida (renovada) ya no se sirve al cliente.
    $this->getJson("/api/mobile/v1/polizas/{$poliza->id}/documentos")
        ->assertOk()
        ->assertJsonCount(0, 'documentos');
});

it('GET /polizas/{id}/documentos incluye un documento de carga manual visible para el cliente', function (): void {
    Storage::fake('r2');
    Storage::disk('r2')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration): string => "https://signed.test/{$path}"
    );

    $pas = makePas();
    $customer = makeCustomer($pas);
    $poliza = makeVehicleRiskWithPoliza($customer);

    // Documento subido por el admin post-emisión (renovación/endoso) marcado visible.
    PolicyDocument::create([
        'poliza_id' => $poliza->id,
        'kind' => 'endoso',
        'storage_path' => "policy-documents/{$poliza->id}/endoso-abc.pdf",
        'original_filename' => 'endoso-cambio-uso.pdf',
        'source' => 'admin_upload',
        'visible_to_client' => true,
        'captured_at' => now(),
    ]);

    $account = MobileAccount::factory()->create(['email' => $customer->email, 'customer_id' => $customer->id]);
    Sanctum::actingAs($account, ['*'], 'mobile');

    $this->getJson("/api/mobile/v1/polizas/{$poliza->id}/documentos")
        ->assertOk()
        ->assertJsonCount(1, 'documentos')
        ->assertJson(['documentos' => [[
            'kind' => 'endoso',
            'url' => "https://signed.test/policy-documents/{$poliza->id}/endoso-abc.pdf",
        ]]]);
});

it('GET /polizas/{id}/documentos devuelve 403 si la póliza no es accesible', function (): void {
    $pas = makePas();
    $otro = makeCustomer($pas, 'otro@example.com');
    $poliza = makeVehicleRiskWithPoliza($otro);

    $account = MobileAccount::factory()->create(['email' => 'andres@gmail.com', 'customer_id' => null]);
    Sanctum::actingAs($account, ['*'], 'mobile');

    $this->getJson("/api/mobile/v1/polizas/{$poliza->id}/documentos")
        ->assertStatus(403)
        ->assertJson(['code' => 'POLIZA_FORBIDDEN']);
});
