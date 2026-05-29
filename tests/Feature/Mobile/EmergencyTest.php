<?php

use App\Models\EmergencyTrackingToken;
use App\Models\MobileAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function authedAccount(): MobileAccount
{
    $a = MobileAccount::factory()->create();
    Sanctum::actingAs($a, ['*'], 'mobile');

    return $a;
}

it('POST /emergencia/notificar requiere auth', function (): void {
    $this->postJson('/api/mobile/v1/emergencia/notificar')
        ->assertStatus(401)
        ->assertJson(['code' => 'UNAUTHENTICATED']);
});

it('Estado 1: devuelve ok sin crear tracking', function (): void {
    authedAccount();

    $this->postJson('/api/mobile/v1/emergencia/notificar', [
        'estado' => 1,
        'lat' => -34.6037,
        'lon' => -58.3816,
    ])
        ->assertOk()
        ->assertJson(['ok' => true, 'estado' => 1])
        ->assertJsonMissing(['tracking_url']);

    expect(EmergencyTrackingToken::count())->toBe(0);
});

it('Estado 2: crea tracking token + devuelve tracking_url', function (): void {
    $me = authedAccount();

    $response = $this->postJson('/api/mobile/v1/emergencia/notificar', [
        'estado' => 2,
        'lat' => -34.6037,
        'lon' => -58.3816,
    ])->assertOk()
        ->assertJsonStructure(['token', 'tracking_url', 'expires_at']);

    $token = $response->json('token');
    expect(EmergencyTrackingToken::where('mobile_account_id', $me->id)->where('token', $token)->exists())->toBeTrue();
    expect($response->json('tracking_url'))->toContain("/track/{$token}");
});

it('rechaza estado fuera de {1,2}', function (): void {
    authedAccount();

    $this->postJson('/api/mobile/v1/emergencia/notificar', [
        'estado' => 99, 'lat' => 0, 'lon' => 0,
    ])->assertStatus(422)->assertJson(['code' => 'VALIDATION_FAILED']);
});

it('rechaza lat/lon fuera de rango', function (): void {
    authedAccount();

    $this->postJson('/api/mobile/v1/emergencia/notificar', [
        'estado' => 1, 'lat' => 200, 'lon' => 0,
    ])->assertStatus(422);
});

it('DELETE /emergencia/tracking/{token} revoca un tracking propio', function (): void {
    $me = authedAccount();
    $token = EmergencyTrackingToken::create([
        'mobile_account_id' => $me->id,
        'token' => 'abc123def',
        'expires_at' => now()->addHours(4),
    ]);

    $this->deleteJson('/api/mobile/v1/emergencia/tracking/abc123def')->assertStatus(204);

    expect($token->fresh()->revoked_at)->not->toBeNull();
});

it('DELETE devuelve 404 si el tracking no es del usuario', function (): void {
    authedAccount();
    $other = MobileAccount::factory()->create();
    EmergencyTrackingToken::create([
        'mobile_account_id' => $other->id,
        'token' => 'xyz999',
        'expires_at' => now()->addHours(4),
    ]);

    $this->deleteJson('/api/mobile/v1/emergencia/tracking/xyz999')
        ->assertStatus(404)
        ->assertJson(['code' => 'TRACKING_NOT_FOUND']);
});

it('GET /track/{token} público devuelve last_lat/lon si está activo', function (): void {
    $account = MobileAccount::factory()->create();
    EmergencyTrackingToken::create([
        'mobile_account_id' => $account->id,
        'token' => 'pub123',
        'last_lat' => -34.6037,
        'last_lon' => -58.3816,
        'last_updated_at' => now(),
        'expires_at' => now()->addHours(2),
    ]);

    $this->getJson('/track/pub123')
        ->assertOk()
        ->assertJson([
            'active' => true,
            'last_lat' => '-34.603700',
            'last_lon' => '-58.381600',
        ]);
});

it('GET /track/{token} público devuelve "ya no disponible" si revocado', function (): void {
    $account = MobileAccount::factory()->create();
    EmergencyTrackingToken::create([
        'mobile_account_id' => $account->id,
        'token' => 'rev123',
        'expires_at' => now()->addHours(2),
        'revoked_at' => now(),
    ]);

    $this->getJson('/track/rev123')
        ->assertStatus(404)
        ->assertJson(['active' => false]);
});

it('GET /track/{token} público devuelve 404 si el token no existe', function (): void {
    // Token sintácticamente válido (alfanumérico) pero inexistente.
    $this->getJson('/track/noexisteinventado')->assertStatus(404)->assertJson(['active' => false]);
});
