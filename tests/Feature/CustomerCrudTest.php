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
        ->and($customer->first_name)->toBe('Juan')
        ->and($customer->last_name)->toBe('Pérez')
        ->and($customer->dni)->toBe('30123456')
        ->and($customer->email)->toBe('juan@email.com')
        ->and($customer->phone)->toBe('+5493512345678')
        ->and($customer->is_anonymous)->toBeFalse();
});

it('crea un cliente desde nombre y apellido separados', function (): void {
    $this->actingAs($this->user)
        ->post(route('customers.store'), [
            'first_name' => 'Ana',
            'last_name' => 'García',
            'email' => 'ana@example.com',
        ])
        ->assertRedirect();

    $customer = Customer::firstOrFail();
    expect($customer->first_name)->toBe('Ana')
        ->and($customer->last_name)->toBe('García')
        ->and($customer->name)->toBe('Ana García');
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
    $customer = Customer::factory()->create(['name' => 'Viejo Nombre', 'first_name' => 'Viejo', 'last_name' => 'Nombre']);

    $this->actingAs($this->user)
        ->put(route('customers.update', $customer), [
            'first_name' => 'Nuevo',
            'last_name' => 'Nombre',
            'dni' => $customer->dni,
        ])
        ->assertRedirect();

    expect($customer->refresh()->name)->toBe('Nuevo Nombre')
        ->and($customer->first_name)->toBe('Nuevo');
});

it('muestra el detalle con sus pólizas', function (): void {
    $customer = Customer::factory()->create();
    $risk = Risk::factory()->create(['customer_id' => $customer->id]);
    Poliza::factory()->create(['risk_id' => $risk->id, 'numero' => 'POL-001', 'estado' => PolizaEstado::Vigente]);

    $this->actingAs($this->user)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Customers/Show')
            ->where('customer.polizas.0.numero', 'POL-001')
            ->where('customer.resumen.polizas_vigentes', 1)
        );
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
