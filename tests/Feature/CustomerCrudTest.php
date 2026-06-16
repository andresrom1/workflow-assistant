<?php

use App\Enums\PolizaEstado;
use App\Models\Customer;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('renderiza el form de alta', function (): void {
    $this->actingAs($this->user)
        ->get(route('customers.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Customers/Create'));
});

it('crea un cliente y normaliza teléfono y email vía el repositorio', function (): void {
    $this->actingAs($this->user)
        ->post(route('customers.store'), [
            'name' => 'Juan Pérez',
            'dni' => '30123456',
            'email' => '  JUAN@Email.com ',
            'phone' => '3512345678',
        ])
        ->assertRedirect();

    $customer = Customer::firstOrFail();
    expect($customer->name)->toBe('Juan Pérez')
        ->and($customer->dni)->toBe('30123456')
        ->and($customer->email)->toBe('juan@email.com')
        ->and($customer->phone)->toBe('+5493512345678')
        ->and($customer->is_anonymous)->toBeFalse();
});

it('exige al menos un identificador', function (): void {
    $this->actingAs($this->user)
        ->post(route('customers.store'), ['name' => 'Sin Datos'])
        ->assertSessionHasErrors('dni');

    expect(Customer::count())->toBe(0);
});

it('rechaza DNI duplicado', function (): void {
    Customer::factory()->create(['dni' => '11222333']);

    $this->actingAs($this->user)
        ->post(route('customers.store'), ['name' => 'Repetido', 'dni' => '11222333'])
        ->assertSessionHasErrors('dni');
});

it('actualiza un cliente', function (): void {
    $customer = Customer::factory()->create(['name' => 'Viejo Nombre']);

    $this->actingAs($this->user)
        ->put(route('customers.update', $customer), [
            'name' => 'Nuevo Nombre',
            'dni' => $customer->dni,
        ])
        ->assertRedirect();

    expect($customer->refresh()->name)->toBe('Nuevo Nombre');
});

it('elimina un cliente sin póliza vigente con soft-delete', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($this->user)
        ->delete(route('customers.destroy', $customer))
        ->assertRedirect(route('customers.index'));

    expect(Customer::count())->toBe(0)
        ->and(Customer::withTrashed()->count())->toBe(1);
});

it('bloquea la eliminación si el cliente tiene una póliza vigente', function (): void {
    $customer = Customer::factory()->create();
    $risk = Risk::factory()->create(['customer_id' => $customer->id]);
    Poliza::factory()->create(['risk_id' => $risk->id, 'estado' => PolizaEstado::Vigente]);

    $this->actingAs($this->user)
        ->delete(route('customers.destroy', $customer))
        ->assertRedirect();

    expect(Customer::find($customer->id))->not->toBeNull();
});
