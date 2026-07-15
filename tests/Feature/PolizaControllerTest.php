<?php

use App\Enums\AssetType;
use App\Enums\PolizaEstado;
use App\Models\Customer;
use App\Models\InsurableAsset;
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
    $asset = InsurableAsset::factory()->create([
        'customer_id' => $customer->id,
        'metadata' => ['patente' => 'AB123CD'],
    ]);
    $risk = Risk::factory()->create(['customer_id' => $customer->id, 'asset_id' => $asset->id]);
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
        ->and($risk->type)->toBe(AssetType::Vehicle)
        ->and($risk->label)->toBe('Toyota Corolla (XY999ZZ)')
        ->and($risk->asset->metadata['patente'])->toBe('XY999ZZ');

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

it('renderiza el formulario de renovación con los datos de la anterior', function (): void {
    $poliza = Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'numero' => 'POL-OLD']);

    $this->actingAs($this->user)
        ->get(route('polizas.renovar.form', $poliza))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Polizas/Renovar')
            ->where('anterior.numero', 'POL-OLD'));
});

it('renueva una póliza: abre una nueva con back-ref y marca la anterior vencida', function (): void {
    $risk = Risk::factory()->create();
    $anterior = Poliza::factory()->create([
        'risk_id' => $risk->id,
        'estado' => PolizaEstado::Vigente,
        'numero' => 'POL-2025',
    ]);

    $this->actingAs($this->user)
        ->post(route('polizas.renovar', $anterior), [
            'numero' => 'POL-2026',
            'company' => 'La Caja Seguros',
            'vigencia' => '2027-04-30',
        ])
        ->assertRedirect();

    expect($anterior->refresh()->estado)->toBe(PolizaEstado::Vencida);

    $nueva = Poliza::where('numero', 'POL-2026')->firstOrFail();
    expect($nueva->risk_id)->toBe($risk->id)
        ->and($nueva->estado)->toBe(PolizaEstado::Vigente)
        ->and($nueva->contrato_anterior_id)->toBe($anterior->id)
        ->and($nueva->vigencia?->toDateString())->toBe('2027-04-30');
});

it('renueva una vencida sin sucesora (caso escalado)', function (): void {
    $anterior = Poliza::factory()->create(['estado' => PolizaEstado::Vencida, 'numero' => 'POL-VIEJA']);

    $this->actingAs($this->user)
        ->post(route('polizas.renovar', $anterior), ['numero' => 'POL-RENOV'])
        ->assertRedirect();

    $nueva = Poliza::where('numero', 'POL-RENOV')->firstOrFail();
    expect($nueva->contrato_anterior_id)->toBe($anterior->id)
        ->and($nueva->estado)->toBe(PolizaEstado::Vigente);
});

it('rechaza renovar una póliza que ya tiene sucesora (doble renovación)', function (): void {
    $anterior = Poliza::factory()->create(['estado' => PolizaEstado::Vencida, 'numero' => 'POL-YA']);
    Poliza::factory()->create([
        'risk_id' => $anterior->risk_id,
        'estado' => PolizaEstado::Vigente,
        'contrato_anterior_id' => $anterior->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('polizas.renovar', $anterior), ['numero' => 'POL-NUEVA'])
        ->assertSessionHasErrors('poliza');

    expect(Poliza::where('numero', 'POL-NUEVA')->exists())->toBeFalse();
});

it('rechaza renovar una póliza de período corto', function (): void {
    $anterior = Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'periodo_corto' => true]);

    $this->actingAs($this->user)
        ->post(route('polizas.renovar', $anterior), ['numero' => 'POL-NUEVA'])
        ->assertSessionHasErrors('poliza');

    expect(Poliza::where('numero', 'POL-NUEVA')->exists())->toBeFalse();
});

it('la cola de vencimientos lista solo vigentes que vencen dentro de la ventana', function (): void {
    Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'vigencia' => now()->addDays(10), 'numero' => 'POL-PRONTO']);
    // Fuera de ventana (vence en 200 días).
    Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'vigencia' => now()->addDays(200), 'numero' => 'POL-LEJOS']);
    // Vencida (no vigente): no entra aunque la fecha caiga dentro.
    Poliza::factory()->create(['estado' => PolizaEstado::Vencida, 'vigencia' => now()->addDays(5), 'numero' => 'POL-VENCIDA']);
    // Vigente sin vigencia: no entra (default conservador, no se inventa fecha).
    Poliza::factory()->create(['estado' => PolizaEstado::Vigente, 'vigencia' => null, 'numero' => 'POL-SINVIG']);

    $this->actingAs($this->user)
        ->get(route('polizas.vencimientos', ['dias' => 30]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Polizas/Vencimientos')
            ->has('polizas', 1)
            ->where('polizas.0.numero', 'POL-PRONTO'));
});

it('elimina una póliza con soft-delete', function (): void {
    $poliza = Poliza::factory()->create();

    $this->actingAs($this->user)
        ->delete(route('polizas.destroy', $poliza))
        ->assertRedirect(route('polizas.index'));

    expect(Poliza::count())->toBe(0)
        ->and(Poliza::withTrashed()->count())->toBe(1);
});

it('descartar renovación setea no_renovar_at y la saca de la cola; reactivar la devuelve', function (): void {
    $poliza = Poliza::factory()->create([
        'estado' => PolizaEstado::Vigente,
        'vigencia' => now()->addDays(10),
    ]);

    expect(Poliza::aRenovar()->whereKey($poliza->id)->exists())->toBeTrue();

    $this->actingAs($this->user)
        ->post(route('polizas.descartar-renovacion', $poliza))
        ->assertRedirect();

    expect($poliza->refresh()->no_renovar_at)->not->toBeNull()
        ->and(Poliza::aRenovar()->whereKey($poliza->id)->exists())->toBeFalse();

    $this->actingAs($this->user)
        ->delete(route('polizas.descartar-renovacion.undo', $poliza))
        ->assertRedirect();

    expect($poliza->refresh()->no_renovar_at)->toBeNull()
        ->and(Poliza::aRenovar()->whereKey($poliza->id)->exists())->toBeTrue();
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
