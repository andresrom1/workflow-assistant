<?php

use App\Exceptions\InvalidFirebaseTokenException;
use App\Models\MobileAccount;
use App\Services\Firebase\FirebaseTokenVerifier;
use App\Services\Firebase\VerifiedIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Bindea un verificador falso que siempre devuelve la identidad dada.
 */
function fakeVerifier(VerifiedIdentity $identity): void
{
    app()->instance(FirebaseTokenVerifier::class, new class($identity) implements FirebaseTokenVerifier
    {
        public function __construct(private VerifiedIdentity $identity) {}

        public function verify(string $idToken): VerifiedIdentity
        {
            return $this->identity;
        }
    });
}

function identity(
    string $uid = 'uid_abc',
    ?string $email = 'tomas@gmail.com',
    ?string $name = 'Tomás Iglesias',
    ?string $avatarUrl = 'https://example.com/a.png',
    bool $emailVerified = true,
): VerifiedIdentity {
    return new VerifiedIdentity($uid, $email, $name, $avatarUrl, $emailVerified);
}

it('intercambia un firebase token por un sanctum token y crea la cuenta móvil', function () {
    fakeVerifier(identity());

    $response = $this->postJson('/api/mobile/v1/auth/session', [
        'firebase_token' => 'cualquier-cosa',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['sanctum_token', 'user' => ['name', 'email', 'avatar_url']])
        ->assertJsonMissingPath('linked')
        ->assertJson([
            'user' => ['name' => 'Tomás Iglesias', 'email' => 'tomas@gmail.com'],
        ]);

    $this->assertDatabaseHas('mobile_accounts', [
        'firebase_uid' => 'uid_abc',
        'email' => 'tomas@gmail.com',
    ]);
});

it('recupera la misma cuenta en logins sucesivos con el mismo firebase_uid', function () {
    fakeVerifier(identity());
    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 't1'])->assertOk();
    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 't2'])->assertOk();

    expect(MobileAccount::where('firebase_uid', 'uid_abc')->count())->toBe(1);
});

it('vincula una cuenta existente por email cuando llega un firebase_uid nuevo', function () {
    // La cuenta ya existía con otro firebase_uid (ej. el usuario re-instaló la
    // app y Google le rotó el UID, o vino un Apple ID nuevo con el mismo email).
    $existing = MobileAccount::factory()->create([
        'firebase_uid' => 'uid_viejo',
        'email' => 'tomas@gmail.com',
    ]);

    fakeVerifier(identity(uid: 'uid_nuevo', email: 'tomas@gmail.com'));
    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 't'])->assertOk();

    // Misma fila, UID actualizado.
    expect(MobileAccount::where('email', 'tomas@gmail.com')->count())->toBe(1);
    expect($existing->fresh()->firebase_uid)->toBe('uid_nuevo');
});

it('no pisa nombre/email con null en logins posteriores (caso Apple)', function () {
    // Primer login: Apple entrega name + email.
    fakeVerifier(identity(uid: 'uid_apple', email: 'ana@icloud.com', name: 'Ana Pérez'));
    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 't1'])->assertOk();

    // Segundo login: Apple manda name/email en null.
    fakeVerifier(identity(uid: 'uid_apple', email: null, name: null, avatarUrl: null));
    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 't2'])->assertOk();

    $this->assertDatabaseHas('mobile_accounts', [
        'firebase_uid' => 'uid_apple',
        'name' => 'Ana Pérez',
        'email' => 'ana@icloud.com',
    ]);
});

it('rechaza el email relay de Apple', function () {
    fakeVerifier(identity(uid: 'uid_relay', email: 'abc123@privaterelay.appleid.com'));

    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 't'])
        ->assertStatus(422)
        ->assertJson(['code' => 'apple_relay_email']);

    $this->assertDatabaseMissing('mobile_accounts', ['firebase_uid' => 'uid_relay']);
});

it('devuelve 401 cuando el token de firebase es inválido', function () {
    app()->instance(FirebaseTokenVerifier::class, new class implements FirebaseTokenVerifier
    {
        public function verify(string $idToken): VerifiedIdentity
        {
            throw new InvalidFirebaseTokenException('token inválido');
        }
    });

    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 'roto'])
        ->assertStatus(401)
        ->assertJson(['code' => 'invalid_firebase_token']);
});

it('requiere firebase_token', function () {
    fakeVerifier(identity());
    $this->postJson('/api/mobile/v1/auth/session', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('firebase_token');
});

it('logout requiere autenticación', function () {
    $this->postJson('/api/mobile/v1/auth/logout')->assertUnauthorized();
});

it('logout borra el token actual', function () {
    $account = MobileAccount::factory()->create();
    $token = $account->createToken('mobile')->plainTextToken;

    $this->withToken($token)->postJson('/api/mobile/v1/auth/logout')->assertOk();

    expect($account->fresh()->tokens()->count())->toBe(0);
});

/**
 * Era la única ruta pública de mobile sin límite: intercambia un ID token por un Sanctum
 * token, así que sin throttle se puede martillar sin costo.
 */
it('corta la ráfaga de logins con 429', function (): void {
    fakeVerifier(identity());

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 'tok']);
    }

    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 'tok'])
        ->assertStatus(429);
});

// ── El email presente tiene que venir verificado (E4 del plan de seguridad) ──

it('rechaza una identidad cuyo email no está verificado', function (): void {
    fakeVerifier(identity(uid: 'uid_sin_verificar', email: 'victima@gmail.com', emailVerified: false));

    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 't'])
        ->assertStatus(422)
        ->assertJson(['code' => 'email_not_verified'])
        ->assertJsonMissingPath('sanctum_token');

    $this->assertDatabaseMissing('mobile_accounts', ['email' => 'victima@gmail.com']);
});

/**
 * El camino que importa: `upsertMobileAccount()` adopta por email una cuenta existente, y
 * esa cuenta puede tener `customer_id`, o sea las pólizas de esa persona.
 *
 * La cuenta previa se crea por factory y no con un primer request: re-bindear el verificador
 * a mitad de test no le llega al request siguiente (el contenedor queda con el fake nuevo
 * pero la request sigue usando el viejo), así que un test de dos llamadas probaría otra cosa.
 */
it('un email sin verificar no puede adoptar la cuenta de otro', function (): void {
    $victima = MobileAccount::factory()->create([
        'firebase_uid' => 'uid_victima',
        'email' => 'victima@gmail.com',
    ]);

    fakeVerifier(identity(uid: 'uid_atacante', email: 'victima@gmail.com', emailVerified: false));

    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 't'])
        ->assertStatus(422)
        ->assertJson(['code' => 'email_not_verified']);

    expect($victima->fresh()->firebase_uid)->toBe('uid_victima');
    $this->assertDatabaseMissing('mobile_accounts', ['firebase_uid' => 'uid_atacante']);
});

it('un uid existente no puede cambiar su email por uno sin verificar', function (): void {
    $cuenta = MobileAccount::factory()->create([
        'firebase_uid' => 'uid_x',
        'email' => 'propio@gmail.com',
    ]);

    fakeVerifier(identity(uid: 'uid_x', email: 'ajeno@gmail.com', emailVerified: false));

    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 't'])
        ->assertStatus(422);

    expect($cuenta->fresh()->email)->toBe('propio@gmail.com');
});
