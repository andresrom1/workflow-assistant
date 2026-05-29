<?php

use App\Models\EmergencyContact;
use App\Models\MobileAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function authAccount(): MobileAccount
{
    $a = MobileAccount::factory()->create();
    Sanctum::actingAs($a, ['*'], 'mobile');

    return $a;
}

it('requiere autenticación', function (): void {
    $this->getJson('/api/mobile/v1/contactos-emergencia')
        ->assertStatus(401)
        ->assertJson(['code' => 'UNAUTHENTICATED']);
});

it('lista solo los contactos del usuario autenticado', function (): void {
    $me = authAccount();
    $other = MobileAccount::factory()->create();

    EmergencyContact::create(['mobile_account_id' => $me->id, 'name' => 'Mamá', 'phone' => '+5491111111111']);
    EmergencyContact::create(['mobile_account_id' => $other->id, 'name' => 'Otro', 'phone' => '+5491122222222']);

    $this->getJson('/api/mobile/v1/contactos-emergencia')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJson(['data' => [['name' => 'Mamá']]]);
});

it('crea un contacto válido', function (): void {
    authAccount();

    $this->postJson('/api/mobile/v1/contactos-emergencia', [
        'name' => 'Papá',
        'phone' => '+5491133333333',
    ])->assertStatus(201)->assertJson(['name' => 'Papá', 'phone' => '+5491133333333']);
});

it('rechaza phone con formato inválido', function (): void {
    authAccount();

    $this->postJson('/api/mobile/v1/contactos-emergencia', [
        'name' => 'Test',
        'phone' => '1156782341', // sin +, no E.164
    ])->assertStatus(422)->assertJson(['code' => 'VALIDATION_FAILED']);
});

it('bloquea al llegar al máximo de contactos (3)', function (): void {
    $me = authAccount();
    foreach (range(1, 3) as $i) {
        EmergencyContact::create([
            'mobile_account_id' => $me->id,
            'name' => "C{$i}",
            'phone' => '+549115678234'.$i,
        ]);
    }

    $this->postJson('/api/mobile/v1/contactos-emergencia', [
        'name' => 'Extra',
        'phone' => '+5491156782349',
    ])->assertStatus(422)->assertJson(['code' => 'CONTACT_LIMIT_REACHED']);
});

it('actualiza un contacto propio', function (): void {
    $me = authAccount();
    $c = EmergencyContact::create(['mobile_account_id' => $me->id, 'name' => 'Viejo', 'phone' => '+5491111111111']);

    $this->putJson("/api/mobile/v1/contactos-emergencia/{$c->id}", ['name' => 'Nuevo'])
        ->assertOk()
        ->assertJson(['name' => 'Nuevo', 'phone' => '+5491111111111']);
});

it('no permite actualizar un contacto ajeno', function (): void {
    authAccount();
    $other = MobileAccount::factory()->create();
    $c = EmergencyContact::create(['mobile_account_id' => $other->id, 'name' => 'X', 'phone' => '+5491111111111']);

    $this->putJson("/api/mobile/v1/contactos-emergencia/{$c->id}", ['name' => 'Hack'])
        ->assertStatus(404)
        ->assertJson(['code' => 'CONTACT_NOT_FOUND']);
});

it('borra un contacto propio', function (): void {
    $me = authAccount();
    $c = EmergencyContact::create(['mobile_account_id' => $me->id, 'name' => 'X', 'phone' => '+5491111111111']);

    $this->deleteJson("/api/mobile/v1/contactos-emergencia/{$c->id}")->assertStatus(204);

    expect(EmergencyContact::find($c->id))->toBeNull();
});
