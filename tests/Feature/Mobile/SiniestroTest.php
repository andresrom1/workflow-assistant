<?php

use App\Enums\PolizaEstado;
use App\Enums\UserRole;
use App\Jobs\SendWhatsAppTemplate;
use App\Models\Customer;
use App\Models\MobileAccount;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\SharedRisk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * El default de la suite es el driver `log` (lo fija phpunit.xml) para que ningún
 * test le pegue a Meta con las credenciales del .env — con `cloud` + QUEUE_CONNECTION=sync
 * el job de template corría inline contra la Cloud API. Los casos que verifican el
 * aviso eligen el transporte real y falsifican la cola, igual que EmergencyDispatchTest.
 */
function transporteWhatsAppFalsificado(): void
{
    config([
        'whatsapp.dispatch_driver' => 'cloud',
        'whatsapp.templates.siniestro_aviso_pas' => ['name' => 'tpl_siniestro_test', 'language' => 'es_AR'],
        'services.whatsapp.phone_number_id' => '999000111',
    ]);

    Queue::fake();
}

/**
 * El destinatario viaja en una propiedad privada del job; se lee con un closure
 * ligado en vez de exponerla solo para el test. El dispatcher normaliza el E.164
 * a dígitos, así que la comparación va sobre esa forma.
 */
function assertAvisoAlPas(string $phoneE164): void
{
    $esperado = preg_replace('/\D/', '', $phoneE164);

    Queue::assertPushed(
        SendWhatsAppTemplate::class,
        fn (SendWhatsAppTemplate $job): bool => (fn (): string => $this->waId)->call($job) === $esperado,
    );
}

it('requiere autenticación', function (): void {
    $this->postJson('/api/mobile/v1/siniestro')
        ->assertStatus(401)
        ->assertJson(['code' => 'UNAUTHENTICATED']);
});

it('notifica al PAS del titular cuando hay Customer linkeado', function (): void {
    transporteWhatsAppFalsificado();

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

    assertAvisoAlPas('+5491156782341');
});

it('cae al fallback (PAS del titular del shared_risk de mayor sum_asegurada)', function (): void {
    transporteWhatsAppFalsificado();

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
    $risk1 = Risk::factory()->create([
        'customer_id' => $tomas->id,
        'label' => 'Honda Civic 2022',
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
    $risk2 = Risk::factory()->create([
        'customer_id' => $otro->id,
        'label' => 'Gol Trend',
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

    assertAvisoAlPas('+5491187654321');
});

it('tier 2 funciona con invitación pendiente (sin accepted_at)', function (): void {
    transporteWhatsAppFalsificado();

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

    $risk = Risk::factory()->create([
        'customer_id' => $tomas->id,
        'label' => 'Honda Civic 2022',
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

    assertAvisoAlPas('+5491187654321');
});

it('cae al PAS por default cuando no hay tier 1 ni 2', function (): void {
    transporteWhatsAppFalsificado();

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

    assertAvisoAlPas('+5491100000000');
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
