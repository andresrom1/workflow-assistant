<?php

use App\Exceptions\InvalidFirebaseTokenException;
use App\Models\Customer;
use App\Models\MobileAccount;
use App\Services\Firebase\FirebaseTokenVerifier;
use App\Services\Firebase\VerifiedIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

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
        ->assertJsonStructure(['sanctum_token', 'user' => ['name', 'email', 'avatar_url'], 'linked'])
        ->assertJson([
            'user' => ['name' => 'Tomás Iglesias', 'email' => 'tomas@gmail.com'],
            'linked' => false,
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

it('vincula la cuenta cuando email + dni matchean un tomador', function () {
    $customer = Customer::factory()->create([
        'email' => 'tomas@gmail.com',
        'dni' => '30123456',
    ]);
    $account = MobileAccount::factory()->create(['email' => 'tomas@gmail.com']);
    Sanctum::actingAs($account, ['*'], 'mobile');

    $this->postJson('/api/mobile/v1/auth/link', ['dni' => '30123456'])
        ->assertOk()
        ->assertJson(['linked' => true]);

    expect($account->fresh()->customer_id)->toBe($customer->id);
});

it('falla la vinculación con dni incorrecto', function () {
    Customer::factory()->create(['email' => 'tomas@gmail.com', 'dni' => '30123456']);
    $account = MobileAccount::factory()->create(['email' => 'tomas@gmail.com']);
    Sanctum::actingAs($account, ['*'], 'mobile');

    $this->postJson('/api/mobile/v1/auth/link', ['dni' => '00000000'])
        ->assertStatus(422)
        ->assertJson(['code' => 'link_failed']);

    expect($account->fresh()->customer_id)->toBeNull();
});

it('no permite vincular un tomador ya reclamado por otra cuenta', function () {
    $customer = Customer::factory()->create(['email' => 'tomas@gmail.com', 'dni' => '30123456']);
    MobileAccount::factory()->linked($customer->id)->create();

    $account = MobileAccount::factory()->create(['email' => 'tomas@gmail.com']);
    Sanctum::actingAs($account, ['*'], 'mobile');

    $this->postJson('/api/mobile/v1/auth/link', ['dni' => '30123456'])
        ->assertStatus(422)
        ->assertJson(['code' => 'link_failed']);
});

it('link y logout requieren autenticación', function () {
    $this->postJson('/api/mobile/v1/auth/link', ['dni' => '30123456'])->assertUnauthorized();
    $this->postJson('/api/mobile/v1/auth/logout')->assertUnauthorized();
});

it('logout borra el token actual', function () {
    $account = MobileAccount::factory()->create();
    $token = $account->createToken('mobile')->plainTextToken;

    $this->withToken($token)->postJson('/api/mobile/v1/auth/logout')->assertOk();

    expect($account->fresh()->tokens()->count())->toBe(0);
});
