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
        ->assertJsonStructure(['token', 'tracking_url', 'update_secret', 'expires_at']);

    $token = $response->json('token');
    expect(EmergencyTrackingToken::where('mobile_account_id', $me->id)->where('token', $token)->exists())->toBeTrue();
    expect($response->json('tracking_url'))->toContain("/track/{$token}");
    // El secreto de escritura va en la respuesta pero NUNCA en la URL pública.
    expect($response->json('update_secret'))->toBeString()->not->toBeEmpty();
    expect($response->json('tracking_url'))->not->toContain($response->json('update_secret'));
});

it('PATCH posicion actualiza last_lat/lon con el update_secret correcto', function (): void {
    $me = authedAccount();
    $row = EmergencyTrackingToken::create([
        'mobile_account_id' => $me->id,
        'token' => 'tok123',
        'update_secret' => 'secret-abc',
        'expires_at' => now()->addHours(4),
    ]);

    $this->patchJson('/api/mobile/v1/emergencia/tracking/tok123/posicion', [
        'update_secret' => 'secret-abc',
        'lat' => -31.4201,
        'lon' => -64.1888,
    ])->assertOk()->assertJson(['ok' => true]);

    $row->refresh();
    expect((float) $row->last_lat)->toBe(-31.4201);
    expect((float) $row->last_lon)->toBe(-64.1888);
    expect($row->last_updated_at)->not->toBeNull();
});

it('PATCH posicion NO requiere Sanctum (lo llama el isolate del foreground service)', function (): void {
    // Sin authedAccount(): el isolate del foreground service no tiene token.
    $other = MobileAccount::factory()->create();
    EmergencyTrackingToken::create([
        'mobile_account_id' => $other->id,
        'token' => 'tokpub',
        'update_secret' => 'secret-pub',
        'expires_at' => now()->addHours(4),
    ]);

    $this->patchJson('/api/mobile/v1/emergencia/tracking/tokpub/posicion', [
        'update_secret' => 'secret-pub',
        'lat' => 0, 'lon' => 0,
    ])->assertOk();
});

it('PATCH posicion devuelve 404 neutro con update_secret incorrecto', function (): void {
    $me = authedAccount();
    EmergencyTrackingToken::create([
        'mobile_account_id' => $me->id,
        'token' => 'tok404',
        'update_secret' => 'el-secreto-real',
        'expires_at' => now()->addHours(4),
    ]);

    $this->patchJson('/api/mobile/v1/emergencia/tracking/tok404/posicion', [
        'update_secret' => 'secreto-equivocado',
        'lat' => 0, 'lon' => 0,
    ])->assertStatus(404)->assertJson(['code' => 'TRACKING_NOT_FOUND']);
});

it('PATCH posicion devuelve 404 si el token no existe', function (): void {
    $this->patchJson('/api/mobile/v1/emergencia/tracking/noexiste/posicion', [
        'update_secret' => 'cualquiera',
        'lat' => 0, 'lon' => 0,
    ])->assertStatus(404)->assertJson(['code' => 'TRACKING_NOT_FOUND']);
});

it('PATCH posicion devuelve 410 si el tracking está revocado', function (): void {
    $me = authedAccount();
    EmergencyTrackingToken::create([
        'mobile_account_id' => $me->id,
        'token' => 'tokrev',
        'update_secret' => 'secret-rev',
        'expires_at' => now()->addHours(4),
        'revoked_at' => now(),
    ]);

    $this->patchJson('/api/mobile/v1/emergencia/tracking/tokrev/posicion', [
        'update_secret' => 'secret-rev',
        'lat' => 0, 'lon' => 0,
    ])->assertStatus(410)->assertJson(['code' => 'TRACKING_INACTIVE']);
});

it('PATCH posicion valida lat/lon en rango', function (): void {
    $me = authedAccount();
    EmergencyTrackingToken::create([
        'mobile_account_id' => $me->id,
        'token' => 'tokval',
        'update_secret' => 'secret-val',
        'expires_at' => now()->addHours(4),
    ]);

    $this->patchJson('/api/mobile/v1/emergencia/tracking/tokval/posicion', [
        'update_secret' => 'secret-val',
        'lat' => 200, 'lon' => 0,
    ])->assertStatus(422)->assertJson(['code' => 'VALIDATION_FAILED']);
});

it('GET /track/{token} JSON nunca expone el update_secret', function (): void {
    $account = MobileAccount::factory()->create();
    EmergencyTrackingToken::create([
        'mobile_account_id' => $account->id,
        'token' => 'toksec',
        'update_secret' => 'no-debe-verse',
        'last_lat' => -34.6,
        'last_lon' => -58.3,
        'last_updated_at' => now(),
        'expires_at' => now()->addHours(2),
    ]);

    $this->getJson('/track/toksec')
        ->assertOk()
        ->assertJsonMissing(['update_secret' => 'no-debe-verse'])
        ->assertJsonMissingPath('update_secret');
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
