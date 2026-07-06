<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBillingCompanyRequest;
use App\Http\Requests\UpdateEmisorRequest;
use App\Models\BillingCompany;
use App\Services\Facturacion\Emisor;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración del módulo de facturación (solo admin): datos del emisor (grupo de settings
 * `facturacion`, leídos vía {@see Emisor}) y ABM del padrón de {@see BillingCompany}.
 */
class FacturacionConfigController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Emisor $emisor,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('Facturacion/Configuracion', [
            'emisor' => $this->emisor->toArray(),
            'companies' => BillingCompany::query()
                ->orderBy('razon_social')
                ->get(['id', 'razon_social', 'cuit', 'condicion_iva', 'domicilio', 'activo']),
        ]);
    }

    public function updateEmisor(UpdateEmisorRequest $request): RedirectResponse
    {
        /** @var Collection<string, mixed> $data */
        $data = collect($request->validated())
            ->mapWithKeys(fn (mixed $value, string $key): array => ["facturacion.{$key}" => $value]);

        $this->settings->saveGroup('facturacion', $data->all());

        return back()->with('flash', ['success' => 'Datos del emisor guardados.']);
    }

    public function storeCompany(StoreBillingCompanyRequest $request): RedirectResponse
    {
        BillingCompany::create($request->validated() + ['activo' => $request->boolean('activo', true)]);

        return back()->with('flash', ['success' => 'Compañía agregada.']);
    }

    public function updateCompany(StoreBillingCompanyRequest $request, BillingCompany $company): RedirectResponse
    {
        $company->update($request->validated() + ['activo' => $request->boolean('activo', true)]);

        return back()->with('flash', ['success' => 'Compañía actualizada.']);
    }

    public function destroyCompany(BillingCompany $company): RedirectResponse
    {
        // Con historial de facturas no se borra (registro contable): se desactiva.
        if ($company->invoices()->exists()) {
            $company->update(['activo' => false]);

            return back()->with('flash', ['success' => 'La compañía tiene facturas emitidas: se desactivó en vez de borrarla.']);
        }

        $company->delete();

        return back()->with('flash', ['success' => 'Compañía eliminada.']);
    }
}
