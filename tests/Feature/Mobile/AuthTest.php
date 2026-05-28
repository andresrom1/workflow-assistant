<?php

use App\Exceptions\InvalidFirebaseTokenException;
use App\Models\Customer;
use App\Models\User;
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

it('intercambia un firebase token por un sanctum token y crea el usuario', function () {
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

    $this->assertDatabaseHas('users', [
        'firebase_uid' => 'uid_abc',
        'email' => 'tomas@gmail.com',
    ]);
});

it('recupera el mismo usuario en logins sucesivos con el mismo firebase_uid', function () {
    fakeVerifier(identity());
    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 't1'])->assertOk();
    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 't2'])->assertOk();

    expect(User::where('firebase_uid', 'uid_abc')->count())->toBe(1);
});

it('no pisa nombre/email con null en logins posteriores (caso Apple)', function () {
    // Primer login: Apple entrega name + email.
    fakeVerifier(identity(uid: 'uid_apple', email: 'ana@icloud.com', name: 'Ana Pérez'));
    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 't1'])->assertOk();

    // Segundo login: Apple manda name/email en null.
    fakeVerifier(identity(uid: 'uid_apple', email: null, name: null, avatarUrl: null));
    $this->postJson('/api/mobile/v1/auth/session', ['firebase_token' => 't2'])->assertOk();

    $this->assertDatabaseHas('users', [
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

    $this->assertDatabaseMissing('users', ['firebase_uid' => 'uid_relay']);
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
    $user = User::factory()->firebase()->create(['email' => 'tomas@gmail.com']);
    Sanctum::actingAs($user);

    $this->postJson('/api/mobile/v1/auth/link', ['dni' => '30123456'])
        ->assertOk()
        ->assertJson(['linked' => true]);

    expect($user->fresh()->customer_id)->toBe($customer->id);
});

it('falla la vinculación con dni incorrecto', function () {
    Customer::factory()->create(['email' => 'tomas@gmail.com', 'dni' => '30123456']);
    $user = User::factory()->firebase()->create(['email' => 'tomas@gmail.com']);
    Sanctum::actingAs($user);

    $this->postJson('/api/mobile/v1/auth/link', ['dni' => '00000000'])
        ->assertStatus(422)
        ->assertJson(['code' => 'link_failed']);

    expect($user->fresh()->customer_id)->toBeNull();
});

it('no permite vincular un tomador ya tomado por otra cuenta', function () {
    $customer = Customer::factory()->create(['email' => 'tomas@gmail.com', 'dni' => '30123456']);
    User::factory()->firebase()->create(['customer_id' => $customer->id]);

    $user = User::factory()->firebase()->create(['email' => 'tomas@gmail.com']);
    Sanctum::actingAs($user);

    $this->postJson('/api/mobile/v1/auth/link', ['dni' => '30123456'])
        ->assertStatus(422)
        ->assertJson(['code' => 'link_failed']);
});

it('link y logout requieren autenticación', function () {
    $this->postJson('/api/mobile/v1/auth/link', ['dni' => '30123456'])->assertUnauthorized();
    $this->postJson('/api/mobile/v1/auth/logout')->assertUnauthorized();
});

it('logout borra el token actual', function () {
    $user = User::factory()->firebase()->create();
    $token = $user->createToken('mobile')->plainTextToken;

    $this->withToken($token)->postJson('/api/mobile/v1/auth/logout')->assertOk();

    expect($user->fresh()->tokens()->count())->toBe(0);
});
