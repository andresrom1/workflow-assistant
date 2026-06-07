<?php

use App\Contracts\WhatsAppDispatcher;
use App\Enums\UserRole;
use App\Jobs\SendWhatsAppTemplate;
use App\Models\EmergencyContact;
use App\Models\EmergencyTrackingToken;
use App\Models\MobileAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Spy del puerto WhatsAppDispatcher: registra las llamadas sin enviar nada.
 * Se bindea en el contenedor para inspeccionar a quién/qué se avisó.
 */
function spyDispatcher(): WhatsAppDispatcher
{
    $spy = new class implements WhatsAppDispatcher
    {
        /** @var list<array{contact: EmergencyContact, userName: string, locationUrl: string, estado: int}> */
        public array $emergencyCalls = [];

        /** @var list<array{pas: User, customerName: string, customerContact: string}> */
        public array $siniestroCalls = [];

        public function emergencyContactNotice(EmergencyContact $contact, string $userName, string $locationUrl, int $estado): void
        {
            $this->emergencyCalls[] = compact('contact', 'userName', 'locationUrl', 'estado');
        }

        public function siniestroNoticeToPas(User $pas, string $customerName, string $customerContact): void
        {
            $this->siniestroCalls[] = compact('pas', 'customerName', 'customerContact');
        }
    };

    app()->instance(WhatsAppDispatcher::class, $spy);

    return $spy;
}

function actingAccount(array $attrs = []): MobileAccount
{
    $account = MobileAccount::factory()->create($attrs);
    Sanctum::actingAs($account, ['*'], 'mobile');

    return $account;
}

function addContacts(MobileAccount $account, int $n): void
{
    for ($i = 1; $i <= $n; $i++) {
        EmergencyContact::create([
            'mobile_account_id' => $account->id,
            'name' => "Contacto {$i}",
            'phone' => '+54911000000'.$i,
        ]);
    }
}

it('Estado 1: avisa a cada contacto con link de Google Maps y nombre del usuario', function (): void {
    $account = actingAccount(['name' => 'Andrés']);
    addContacts($account, 3);
    $spy = spyDispatcher();

    $this->postJson('/api/mobile/v1/emergencia/notificar', [
        'estado' => 1, 'lat' => -34.6037, 'lon' => -58.3816,
    ])->assertOk()->assertJson(['ok' => true, 'estado' => 1]);

    expect($spy->emergencyCalls)->toHaveCount(3);
    expect($spy->emergencyCalls[0]['estado'])->toBe(1);
    expect($spy->emergencyCalls[0]['userName'])->toBe('Andrés');
    expect($spy->emergencyCalls[0]['locationUrl'])
        ->toBe('https://www.google.com/maps?q=-34.6037,-58.3816');
});

it('Estado 2: avisa con el tracking_url y NUNCA con el update_secret', function (): void {
    $account = actingAccount(['name' => 'Andrés']);
    addContacts($account, 2);
    $spy = spyDispatcher();

    $response = $this->postJson('/api/mobile/v1/emergencia/notificar', [
        'estado' => 2, 'lat' => -34.6037, 'lon' => -58.3816,
    ])->assertOk();

    $token = EmergencyTrackingToken::first();
    $trackingUrl = $response->json('tracking_url');

    expect($spy->emergencyCalls)->toHaveCount(2);
    foreach ($spy->emergencyCalls as $call) {
        expect($call['estado'])->toBe(2);
        expect($call['locationUrl'])->toBe($trackingUrl);
        expect($call['locationUrl'])->toContain("/track/{$token->token}");
        // La llave de escritura jamás se le pasa al contacto.
        expect($call['locationUrl'])->not->toContain($token->update_secret);
    }
});

it('sin contactos no avisa a nadie pero responde ok', function (): void {
    actingAccount();
    $spy = spyDispatcher();

    $this->postJson('/api/mobile/v1/emergencia/notificar', [
        'estado' => 1, 'lat' => 0, 'lon' => 0,
    ])->assertOk();

    expect($spy->emergencyCalls)->toBeEmpty();
});

it('solo avisa a los contactos de la propia cuenta', function (): void {
    $account = actingAccount();
    addContacts($account, 1);

    // Contactos de otra cuenta: no deben recibir aviso.
    $other = MobileAccount::factory()->create();
    addContacts($other, 3);

    $spy = spyDispatcher();

    $this->postJson('/api/mobile/v1/emergencia/notificar', [
        'estado' => 1, 'lat' => 0, 'lon' => 0,
    ])->assertOk();

    expect($spy->emergencyCalls)->toHaveCount(1);
});

it('driver cloud: encola un SendWhatsAppTemplate por contacto', function (): void {
    config(['whatsapp.dispatch_driver' => 'cloud']);
    config(['services.whatsapp.phone_number_id' => '123456']);
    Queue::fake();

    $account = actingAccount(['name' => 'Andrés']);
    addContacts($account, 2);

    $this->postJson('/api/mobile/v1/emergencia/notificar', [
        'estado' => 2, 'lat' => -34.6, 'lon' => -58.3,
    ])->assertOk();

    Queue::assertPushed(SendWhatsAppTemplate::class, 2);
});

it('siniestro: avisa al PAS por default con el contacto del cliente', function (): void {
    config(['mango.default_pas_email' => 'pas@mango.com']);
    $pas = User::factory()->create([
        'role' => UserRole::Pas,
        'name' => 'Andrés Romero',
        'email' => 'pas@mango.com',
        'metadata' => ['phone' => '+5491100000000'],
    ]);

    actingAccount(['name' => 'Cliente Test', 'email' => 'cliente@test.com', 'customer_id' => null]);
    $spy = spyDispatcher();

    $this->postJson('/api/mobile/v1/siniestro')->assertOk();

    expect($spy->siniestroCalls)->toHaveCount(1);
    expect($spy->siniestroCalls[0]['pas']->id)->toBe($pas->id);
    expect($spy->siniestroCalls[0]['customerName'])->toBe('Cliente Test');
    // Sin Customer linkeado, el contacto cae al email de la cuenta.
    expect($spy->siniestroCalls[0]['customerContact'])->toBe('cliente@test.com');
});
