<?php

use App\Enums\PolizaEstado;
use App\Enums\RiskType;
use App\Models\Customer;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('requiere autenticación', function (): void {
    $this->get(route('polizas.index'))->assertRedirect('/login');
});

it('renderiza el index con el buscador', function (): void {
    Poliza::factory()->create(['numero' => 'POL-INDEX']);

    $this->actingAs($this->user)
        ->get(route('polizas.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Polizas/Index'));
});

it('filtra el index por número, patente y cliente', function (): void {
    $customer = Customer::factory()->create(['name' => 'Marta Gómez']);
    $risk = Risk::factory()->create([
        'customer_id' => $customer->id,
        'metadata' => ['patente' => 'AB123CD'],
    ]);
    Poliza::factory()->create(['risk_id' => $risk->id, 'numero' => 'POL-FIND']);
    Poliza::factory()->create(['numero' => 'POL-OTHER']);

    $this->actingAs($this->user)
        ->get(route('polizas.index', ['search' => 'Marta']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('polizas.data', 1)
            ->where('polizas.data.0.numero', 'POL-FIND'));

    $this->actingAs($this->user)
        ->get(route('polizas.index', ['search' => 'AB123CD']))
        ->assertInertia(fn ($page) => $page->has('polizas.data', 1)
            ->where('polizas.data.0.numero', 'POL-FIND'));
});

it('crea una póliza con un Risk nuevo', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($this->user)
        ->post(route('polizas.store'), [
            'customer_id' => $customer->id,
            'risk' => [
                'patente' => 'XY999ZZ',
                'marca' => 'Toyota',
                'modelo' => 'Corolla',
                'year' => 2021,
            ],
            'numero' => 'POL-NEW',
            'company' => 'La Caja Seguros',
            'estado' => PolizaEstado::Vigente->value,
        ])
        ->assertRedirect();

    $risk = Risk::firstOrFail();
    expect($risk->customer_id)->toBe($customer->id)
        ->and($risk->type)->toBe(RiskType::Vehicle)
        ->and($risk->label)->toBe('Toyota Corolla (XY999ZZ)')
        ->and($risk->metadata['patente'])->toBe('XY999ZZ');

    $poliza = Poliza::firstOrFail();
    expect($poliza->risk_id)->toBe($risk->id)
        ->and($poliza->numero)->toBe('POL-NEW')
        ->and($poliza->estado)->toBe(PolizaEstado::Vigente);
});

it('crea una póliza reusando un Risk existente del cliente', function (): void {
    $customer = Customer::factory()->create();
    $risk = Risk::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($this->user)
        ->post(route('polizas.store'), [
            'customer_id' => $customer->id,
            'risk_id' => $risk->id,
            'numero' => 'POL-REUSE',
            'estado' => PolizaEstado::Emitida->value,
        ])
        ->assertRedirect();

    expect(Risk::count())->toBe(1);
    expect(Poliza::firstOrFail()->risk_id)->toBe($risk->id);
});

it('rechaza un Risk que no pertenece al cliente', function (): void {
    $customer = Customer::factory()->create();
    $otherRisk = Risk::factory()->create();

    $this->actingAs($this->user)
        ->post(route('polizas.store'), [
            'customer_id' => $customer->id,
            'risk_id' => $otherRisk->id,
            'estado' => PolizaEstado::Vigente->value,
        ])
        ->assertSessionHasErrors('risk_id');

    expect(Poliza::count())->toBe(0);
});

it('rechaza una segunda póliza vigente en el mismo Risk', function (): void {
    $risk = Risk::factory()->create();
    Poliza::factory()->create(['risk_id' => $risk->id, 'estado' => PolizaEstado::Vigente]);

    $this->actingAs($this->user)
        ->post(route('polizas.store'), [
            'customer_id' => $risk->customer_id,
            'risk_id' => $risk->id,
            'numero' => 'POL-DUP',
            'estado' => PolizaEstado::Vigente->value,
        ])
        ->assertSessionHasErrors('estado');

    expect(Poliza::where('numero', 'POL-DUP')->exists())->toBeFalse();
});

it('actualiza una póliza', function (): void {
    $poliza = Poliza::factory()->create(['estado' => PolizaEstado::Emitida, 'numero' => 'OLD']);

    $this->actingAs($this->user)
        ->put(route('polizas.update', $poliza), [
            'numero' => 'NEW-NUM',
            'estado' => PolizaEstado::Vigente->value,
        ])
        ->assertRedirect();

    expect($poliza->refresh()->numero)->toBe('NEW-NUM')
        ->and($poliza->estado)->toBe(PolizaEstado::Vigente);
});

it('al actualizar respeta el constraint de única vigente por Risk', function (): void {
    $risk = Risk::factory()->create();
    Poliza::factory()->create(['risk_id' => $risk->id, 'estado' => PolizaEstado::Vigente]);
    $segunda = Poliza::factory()->create(['risk_id' => $risk->id, 'estado' => PolizaEstado::Emitida]);

    $this->actingAs($this->user)
        ->put(route('polizas.update', $segunda), [
            'estado' => PolizaEstado::Vigente->value,
        ])
        ->assertSessionHasErrors('estado');

    expect($segunda->refresh()->estado)->toBe(PolizaEstado::Emitida);
});

it('elimina una póliza con soft-delete', function (): void {
    $poliza = Poliza::factory()->create();

    $this->actingAs($this->user)
        ->delete(route('polizas.destroy', $poliza))
        ->assertRedirect(route('polizas.index'));

    expect(Poliza::count())->toBe(0)
        ->and(Poliza::withTrashed()->count())->toBe(1);
});

it('valida que el estado sea requerido al crear', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs($this->user)
        ->post(route('polizas.store'), [
            'customer_id' => $customer->id,
            'risk' => ['marca' => 'Ford'],
        ])
        ->assertSessionHasErrors('estado');
});
