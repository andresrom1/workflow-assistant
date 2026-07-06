<?php

use App\Models\BillingCompany;
use App\Models\Invoice;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Facturacion\Emisor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function emisorPayload(array $override = []): array
{
    return array_merge([
        'razon_social' => 'Andrés Romero',
        'cuit' => '20304050607',
        'punto_venta' => 2,
        'condicion_iva' => 'Responsable Monotributo',
        'subtitulo' => 'Productor Asesor de Seguros',
        'domicilio' => 'Av. Siempreviva 742',
        'ingresos_brutos' => '901-123456-7',
        'inicio_actividades' => '01/2015',
    ], $override);
}

it('renderiza la configuración con emisor y compañías', function (): void {
    $admin = User::factory()->admin()->create();
    BillingCompany::factory()->create(['razon_social' => 'Cooperación Seguros']);

    $this->actingAs($admin)
        ->get(route('admin.facturacion.configuracion'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Facturacion/Configuracion')
            ->has('emisor')
            ->has('companies', 1));
});

it('guarda los datos del emisor y los expone vía Emisor', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.facturacion.emisor.update'), emisorPayload(['razon_social' => 'Mi Empresa SA']))
        ->assertRedirect();

    expect(SystemSetting::find('facturacion.razon_social')->value)->toBe('Mi Empresa SA')
        ->and(SystemSetting::find('facturacion.cuit')->value)->toBe('20304050607');

    $emisor = app(Emisor::class);
    expect($emisor->razonSocial())->toBe('Mi Empresa SA')
        ->and($emisor->cuit())->toBe('20304050607')
        ->and($emisor->puntoVenta())->toBe(2);
});

it('valida el CUIT del emisor', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.facturacion.emisor.update'), emisorPayload(['cuit' => '123']))
        ->assertInvalid(['cuit']);
});

it('agrega una compañía', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.facturacion.companies.store'), [
            'razon_social' => 'LPS Seguros',
            'cuit' => '30111222333',
            'condicion_iva' => 'RI',
        ])
        ->assertRedirect();

    expect(BillingCompany::where('cuit', '30111222333')->exists())->toBeTrue();
});

it('rechaza una compañía con CUIT duplicado', function (): void {
    $admin = User::factory()->admin()->create();
    BillingCompany::factory()->create(['cuit' => '30111222333']);

    $this->actingAs($admin)
        ->post(route('admin.facturacion.companies.store'), [
            'razon_social' => 'Otra',
            'cuit' => '30111222333',
            'condicion_iva' => 'RI',
        ])
        ->assertInvalid(['cuit']);
});

it('edita una compañía', function (): void {
    $admin = User::factory()->admin()->create();
    $company = BillingCompany::factory()->create(['razon_social' => 'Viejo Nombre']);

    $this->actingAs($admin)
        ->put(route('admin.facturacion.companies.update', $company), [
            'razon_social' => 'Nuevo Nombre',
            'cuit' => $company->cuit,
            'condicion_iva' => 'RI',
            'activo' => false,
        ])
        ->assertRedirect();

    expect($company->refresh()->razon_social)->toBe('Nuevo Nombre')
        ->and($company->activo)->toBeFalse();
});

it('borra una compañía sin facturas', function (): void {
    $admin = User::factory()->admin()->create();
    $company = BillingCompany::factory()->create();

    $this->actingAs($admin)
        ->delete(route('admin.facturacion.companies.destroy', $company))
        ->assertRedirect();

    expect(BillingCompany::find($company->id))->toBeNull();
});

it('desactiva (no borra) una compañía con facturas emitidas', function (): void {
    $admin = User::factory()->admin()->create();
    $company = BillingCompany::factory()->create();
    Invoice::factory()->create(['billing_company_id' => $company->id]);

    $this->actingAs($admin)
        ->delete(route('admin.facturacion.companies.destroy', $company))
        ->assertRedirect();

    expect($company->refresh())->not->toBeNull()
        ->and($company->activo)->toBeFalse();
});

it('bloquea el acceso a no-admin', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.facturacion.configuracion'))->assertForbidden();
    $this->actingAs($user)->put(route('admin.facturacion.emisor.update'), emisorPayload())->assertForbidden();
});
