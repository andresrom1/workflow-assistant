<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('login screen can be rendered', function () {
    $this->get('/login')->assertStatus(200);
});

test('users can authenticate and are redirected to customers', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
});

test('users cannot authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

test('unauthenticated requests are redirected to login', function () {
    $this->get('/customers')->assertRedirect('/login');
    $this->get('/quotes')->assertRedirect('/login');
    $this->get('/admin/checkout-sessions')->assertRedirect('/login');
});

test('checkout routes are not behind auth middleware', function () {
    // Verificar que la ruta de checkout no tiene el middleware auth
    // (un token inválido retorna 404, no redirect a /login)
    $response = $this->get('/checkout/token-that-does-not-exist');
    $response->assertStatus(404);
});

test('register route does not exist', function () {
    $this->get('/register')->assertStatus(404);
    $this->post('/register')->assertStatus(404);
});

test('non-admin users receive 403 on admin routes', function () {
    $user = User::factory()->create(['role' => UserRole::User]);

    $this->actingAs($user)->get('/admin/checkout-sessions')->assertForbidden();
    $this->actingAs($user)->get('/admin/users/create')->assertForbidden();
});

test('admin users can access admin routes', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/checkout-sessions')->assertOk();
    $this->actingAs($admin)->get('/admin/users/create')->assertOk();
});

test('admin can create a new user', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'Nuevo Usuario',
        'email' => 'nuevo@test.com',
        'password' => 'secret12345',
        'password_confirmation' => 'secret12345',
        'role' => UserRole::User->value,
    ])->assertRedirect(route('admin.users.create'));

    $this->assertDatabaseHas('users', ['email' => 'nuevo@test.com', 'role' => 'user']);
});

test('creating user with duplicate email fails validation', function () {
    $admin = User::factory()->admin()->create();
    $existing = User::factory()->create(['email' => 'existente@test.com']);

    $this->actingAs($admin)->post('/admin/users', [
        'name' => 'Otro',
        'email' => 'existente@test.com',
        'password' => 'secret12345',
        'password_confirmation' => 'secret12345',
        'role' => UserRole::User->value,
    ])->assertSessionHasErrors('email');
});

test('admin can reset a user password', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->post("/admin/users/{$user->id}/reset-password")
        ->assertRedirect(route('admin.users.create'))
        ->assertSessionHas('success');
});

test('admin can delete a user', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->delete("/admin/users/{$user->id}")
        ->assertRedirect(route('admin.users.create'))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('admin cannot delete their own account', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->delete("/admin/users/{$admin->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});

test('non-admin cannot delete users', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->delete("/admin/users/{$other->id}")
        ->assertForbidden();
});

test('authenticated user can view their profile page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')->assertOk();
});

test('user can update their name and email', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/profile', [
        'name' => 'Nuevo Nombre',
        'email' => 'nuevo@example.com',
    ])->assertRedirect();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Nuevo Nombre',
        'email' => 'nuevo@example.com',
    ]);
});

test('user can update their password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/profile', [
        'name' => $user->name,
        'email' => $user->email,
        'password' => 'newpassword1',
        'password_confirmation' => 'newpassword1',
    ])->assertRedirect()->assertSessionHas('success');
});

test('password is not changed when left empty', function () {
    $user = User::factory()->create();
    $originalHash = $user->password;

    $this->actingAs($user)->put('/profile', [
        'name' => $user->name,
        'email' => $user->email,
    ])->assertRedirect();

    expect($user->fresh()->password)->toBe($originalHash);
});

test('email must be unique except own', function () {
    $user = User::factory()->create();
    $other = User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($user)->put('/profile', [
        'name' => $user->name,
        'email' => 'taken@example.com',
    ])->assertSessionHasErrors('email');

    // Own email does not trigger unique error
    $this->actingAs($user)->put('/profile', [
        'name' => $user->name,
        'email' => $user->email,
    ])->assertSessionHasNoErrors();
});
