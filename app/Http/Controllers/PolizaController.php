<?php

namespace App\Http\Controllers;

use App\Enums\PolizaEstado;
use App\Models\Customer;
use App\Models\Poliza;
use App\Services\PolizaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alta, edición y baja manual de pólizas desde el panel.
 *
 * Una póliza cuelga de un Risk (vehículo) que a su vez cuelga de un Customer, así que
 * crear una implica resolver cliente + Risk (reusar uno existente o crear uno nuevo).
 * La carga de documentos de la póliza vive en PolicyDocumentController (cross-link
 * desde la vista de edición).
 */
class PolizaController extends Controller
{
    public function __construct(
        protected PolizaService $polizaService,
    ) {}

    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $perPage = (int) $request->input('per_page', 25);

        $polizas = Poliza::query()
            ->with('risk.customer')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('numero', 'ilike', "%{$search}%")
                        ->orWhereHas('risk', fn ($r) => $r->where('metadata->patente', 'ilike', "%{$search}%"))
                        ->orWhereHas('risk.customer', fn ($c) => $c->where('name', 'ilike', "%{$search}%"));
                });
            })
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Poliza $poliza): array => [
                'id' => $poliza->id,
                'numero' => $poliza->numero,
                'company' => $poliza->company,
                'coverage' => $poliza->coverage,
                'patente' => $poliza->risk->metadata['patente'] ?? null,
                'cliente' => $poliza->risk->customer?->name,
                'estado' => $poliza->estado->value,
                'vigencia' => $poliza->vigencia?->toDateString(),
            ]);

        return Inertia::render('Polizas/Index', [
            'polizas' => $polizas,
            'filters' => ['search' => $search, 'per_page' => $perPage],
        ]);
    }

    /**
     * Cola de vencimientos: pólizas vigentes cuyo término vence dentro de `dias` (o ya
     * venció, por término sin actualizar) — la señal para cargar la renovación. Derivada
     * de `vigencia`, sin persistir nada. Las legacy sin `vigencia` no aparecen (default
     * conservador: no inventar fecha).
     */
    public function vencimientos(Request $request): Response
    {
        $dias = max(1, min(365, (int) $request->input('dias', 30)));
        $limite = now()->addDays($dias)->endOfDay();

        $polizas = Poliza::query()
            ->with('risk.customer')
            ->where('estado', PolizaEstado::Vigente)
            ->whereNotNull('vigencia')
            ->where('vigencia', '<=', $limite)
            ->orderBy('vigencia')
            ->get()
            ->map(fn (Poliza $p): array => [
                'id' => $p->id,
                'numero' => $p->numero,
                'company' => $p->company,
                'patente' => $p->risk->metadata['patente'] ?? null,
                'label' => $p->risk->label,
                'cliente' => $p->risk->customer?->name,
                'vigencia' => $p->vigencia?->toDateString(),
            ])->all();

        return Inertia::render('Polizas/Vencimientos', [
            'polizas' => $polizas,
            'dias' => $dias,
        ]);
    }

    public function create(Request $request): Response
    {
        $customerId = $request->integer('customer');
        $customerSearch = trim((string) $request->input('customer_search', ''));

        $customer = null;
        $matches = [];

        if ($customerId > 0) {
            $found = Customer::with('risks')->find($customerId);
            if ($found instanceof Customer) {
                $customer = [
                    'id' => $found->id,
                    'name' => $found->name,
                    'dni' => $found->dni,
                    'risks' => $found->risks->map(fn ($r): array => [
                        'id' => $r->id,
                        'label' => $r->label,
                        'patente' => $r->metadata['patente'] ?? null,
                    ])->all(),
                ];
            }
        } elseif ($customerSearch !== '') {
            $matches = Customer::query()
                ->where(function ($q) use ($customerSearch): void {
                    $q->where('name', 'ilike', "%{$customerSearch}%")
                        ->orWhere('dni', 'ilike', "%{$customerSearch}%")
                        ->orWhere('email', 'ilike', "%{$customerSearch}%")
                        ->orWhere('phone', 'ilike', "%{$customerSearch}%");
                })
                ->orderBy('name')
                ->limit(15)
                ->get()
                ->map(fn (Customer $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'dni' => $c->dni,
                    'email' => $c->email,
                ])->all();
        }

        return Inertia::render('Polizas/Create', [
            'customer' => $customer,
            'customerMatches' => $matches,
            'customerSearch' => $customerSearch,
            'estados' => $this->estadoOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'risk_id' => 'nullable|integer|exists:risks,id',
            'risk' => 'nullable|required_without:risk_id|array',
            'risk.patente' => 'nullable|string|max:20',
            'risk.marca' => 'nullable|string|max:60',
            'risk.modelo' => 'nullable|string|max:60',
            'risk.version' => 'nullable|string|max:120',
            'risk.year' => 'nullable|integer|min:1900|max:2100',
            'risk.combustible' => 'nullable|string|max:30',
            'risk.uso' => 'nullable|string|max:30',
            'risk.codigo_postal' => 'nullable|string|max:10',
            ...$this->polizaRules(),
        ]);

        $customer = Customer::findOrFail($validated['customer_id']);

        $poliza = $this->polizaService->createManual(
            customer: $customer,
            existingRiskId: $validated['risk_id'] ?? null,
            riskData: $validated['risk'] ?? null,
            polizaData: $this->polizaPayload($validated),
        );

        return redirect()->route('polizas.edit', $poliza)
            ->with('flash', ['success' => 'Póliza creada.']);
    }

    public function edit(Poliza $poliza): Response
    {
        $poliza->load('risk.customer');

        return Inertia::render('Polizas/Edit', [
            'poliza' => [
                'id' => $poliza->id,
                'numero' => $poliza->numero,
                'company' => $poliza->company,
                'coverage' => $poliza->coverage,
                'coverage_detail' => $poliza->coverage_detail,
                'sum_asegurada' => $poliza->sum_asegurada,
                'cuota' => $poliza->cuota,
                'cuota_due' => $poliza->cuota_due?->toDateString(),
                'vigencia' => $poliza->vigencia?->toDateString(),
                'emitida_en' => $poliza->emitida_en?->toDateString(),
                'estado' => $poliza->estado->value,
                'periodo_corto' => $poliza->periodo_corto,
                'no_renovar_at' => $poliza->no_renovar_at?->toIso8601String(),
                'es_renovable' => $poliza->esRenovable(),
            ],
            'vehicle' => [
                'label' => $poliza->risk->label,
                'patente' => $poliza->risk->metadata['patente'] ?? null,
                'cliente' => $poliza->risk->customer?->name,
                'customer_id' => $poliza->risk->customer_id,
            ],
            'estados' => $this->estadoOptions(),
        ]);
    }

    public function update(Request $request, Poliza $poliza): RedirectResponse
    {
        $validated = $request->validate($this->polizaRules());

        $this->polizaService->update($poliza, $this->polizaPayload($validated));

        return back()->with('flash', ['success' => 'Póliza actualizada.']);
    }

    public function destroy(Poliza $poliza): RedirectResponse
    {
        $poliza->delete();

        return redirect()->route('polizas.index')
            ->with('flash', ['success' => 'Póliza eliminada.']);
    }

    /**
     * Formulario de renovación: prellena los datos de la póliza vigente para que el
     * operador cargue la renovada (número nuevo). El estado lo fuerza el servicio.
     */
    public function renovarForm(Poliza $poliza): Response
    {
        $poliza->load('risk.customer');

        return Inertia::render('Polizas/Renovar', [
            'anterior' => [
                'id' => $poliza->id,
                'numero' => $poliza->numero,
                'company' => $poliza->company,
                'coverage' => $poliza->coverage,
                'coverage_detail' => $poliza->coverage_detail,
                'sum_asegurada' => $poliza->sum_asegurada,
                'cuota' => $poliza->cuota,
                'vigencia' => $poliza->vigencia?->toDateString(),
                'estado' => $poliza->estado->value,
            ],
            'vehicle' => [
                'label' => $poliza->risk->label,
                'patente' => $poliza->risk->metadata['patente'] ?? null,
                'cliente' => $poliza->risk->customer?->name,
            ],
        ]);
    }

    public function renovar(Request $request, Poliza $poliza): RedirectResponse
    {
        // La renovada nace `vigente` (lo fuerza el servicio) — el form no manda estado.
        $rules = $this->polizaRules();
        unset($rules['estado']);
        $validated = $request->validate($rules);

        $nueva = $this->polizaService->renovar($poliza, $this->polizaFields($validated));

        return redirect()->route('polizas.edit', $nueva)
            ->with('flash', ['success' => 'Póliza renovada. La anterior quedó marcada como vencida.']);
    }

    /**
     * Descarte honesto: la póliza sale de la cola de renovación sin anularse (queda
     * vencida cuando llegue su término, pero no figura como pendiente). Derivado —
     * setear `no_renovar_at` la excluye de `scopeARenovar()`.
     */
    public function descartarRenovacion(Poliza $poliza): RedirectResponse
    {
        $poliza->update(['no_renovar_at' => now()]);

        return back()->with('flash', ['success' => 'Póliza marcada como no renovable.']);
    }

    public function reactivarRenovacion(Poliza $poliza): RedirectResponse
    {
        $poliza->update(['no_renovar_at' => null]);

        return back()->with('flash', ['success' => 'Renovación reactivada.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function polizaRules(): array
    {
        return [
            'numero' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'coverage' => 'nullable|string|max:255',
            'coverage_detail' => 'nullable|string|max:255',
            'sum_asegurada' => 'nullable|numeric|min:0',
            'cuota' => 'nullable|numeric|min:0',
            'cuota_due' => 'nullable|date',
            'vigencia' => 'nullable|date',
            'emitida_en' => 'nullable|date',
            'periodo_corto' => 'boolean',
            'estado' => ['required', Rule::enum(PolizaEstado::class)],
        ];
    }

    /**
     * Campos de la póliza sin `estado` (compartidos por alta/edición/renovación).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function polizaFields(array $validated): array
    {
        return [
            'numero' => $validated['numero'] ?? null,
            'company' => $validated['company'] ?? null,
            'coverage' => $validated['coverage'] ?? null,
            'coverage_detail' => $validated['coverage_detail'] ?? null,
            'sum_asegurada' => $validated['sum_asegurada'] ?? null,
            'cuota' => $validated['cuota'] ?? null,
            'cuota_due' => $validated['cuota_due'] ?? null,
            'vigencia' => $validated['vigencia'] ?? null,
            'emitida_en' => $validated['emitida_en'] ?? null,
            'periodo_corto' => $validated['periodo_corto'] ?? false,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function polizaPayload(array $validated): array
    {
        return [
            ...$this->polizaFields($validated),
            'estado' => PolizaEstado::from($validated['estado']),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function estadoOptions(): array
    {
        return array_map(
            fn (PolizaEstado $e): array => ['value' => $e->value, 'label' => ucfirst($e->value)],
            PolizaEstado::cases(),
        );
    }
}
