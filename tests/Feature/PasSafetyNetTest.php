<?php

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Repositories\CustomerRepository;
use App\Services\CustomerIdentificationService;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Red de seguridad: ningún Customer debe quedar sin PAS, ni al nacer (chat/checkout)
 * ni por el borrado de otro user. El PAS por default se resuelve por
 * {@see User::defaultPas()} (config con fallback a AdminUserSeeder::EMAIL).
 */
function defaultPasUser(): User
{
    return User::where('email', AdminUserSeeder::EMAIL)->firstOrFail();
}

it('resuelve el PAS por default por email canónico cuando no hay env', function (): void {
    $this->seed(AdminUserSeeder::class);

    expect(User::defaultPas()?->email)->toBe(AdminUserSeeder::EMAIL);
});

it('asigna el PAS por default al crear un customer vía el repositorio', function (): void {
    $this->seed(AdminUserSeeder::class);

    $customer = app(CustomerRepository::class)->create([
        'email' => 'nuevo@example.com',
    ]);

    expect($customer->pas_id)->toBe(defaultPasUser()->id);
});

it('respeta un pas_id explícito al crear', function (): void {
    $this->seed(AdminUserSeeder::class);
    $otro = User::factory()->create(['role' => UserRole::Pas]);

    $customer = app(CustomerRepository::class)->create([
        'email' => 'explicito@example.com',
        'pas_id' => $otro->id,
    ]);

    expect($customer->pas_id)->toBe($otro->id);
});

it('asigna el PAS por default al identificar un customer nuevo por el service', function (): void {
    $this->seed(AdminUserSeeder::class);

    $customer = app(CustomerIdentificationService::class)->findOrCreate('email', 'chat@example.com');

    expect($customer->pas_id)->toBe(defaultPasUser()->id);
});

it('reasigna los customers al PAS por default cuando se borra su PAS', function (): void {
    $this->seed(AdminUserSeeder::class);
    $default = defaultPasUser();

    $pas = User::factory()->create(['role' => UserRole::Pas]);
    $customer = Customer::factory()->create(['pas_id' => $pas->id]);

    $pas->delete();

    expect($customer->refresh()->pas_id)
        ->toBe($default->id)
        ->not->toBeNull();
});

it('bloquea el borrado del PAS por default desde el panel admin', function (): void {
    $this->seed(AdminUserSeeder::class);
    $default = defaultPasUser();
    $customer = Customer::factory()->create(['pas_id' => $default->id]);

    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $default))
        ->assertForbidden();

    expect($default->fresh())->not->toBeNull()
        ->and($customer->refresh()->pas_id)->toBe($default->id);
});
