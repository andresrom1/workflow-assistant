<?php

namespace App\Http\Controllers;

use App\Enums\PolizaEstado;
use App\Models\CheckoutSession;
use App\Models\Customer;
use App\Models\PolicyDocument;
use App\Models\User;
use App\Repositories\CustomerRepository;
use App\Services\CustomerConsolidationService;
use App\Services\CustomerIdentificationService;
use App\Services\Visred\VisredCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    /** Campos de identidad (person_holder + domicilio del tomador) que cura el admin. */
    private const HOLDER_FIELDS = [
        'first_name', 'last_name', 'dni', 'document_type_id', 'person_type_id',
        'birthdate', 'sex_id', 'tax_condition_id', 'email', 'phone',
        'domicilio_calle', 'domicilio_numero', 'domicilio_cp',
        'domicilio_provincia', 'domicilio_localidad',
    ];

    public function __construct(
        protected CustomerRepository $customerRepository,
        protected CustomerConsolidationService $consolidation,
        protected CustomerIdentificationService $identification,
    ) {}

    public function index(Request $request): Response
    {
        $search = (string) $request->input('search', '');
        $perPage = (int) $request->input('per_page', 15);

        $customers = $this->customerRepository->getAllWithRelations(
            ['vehicles', 'conversations', 'risks.polizas'],
            $search,
            $perPage
        );

        return Inertia::render('Customers/Index', [
            'customers' => $customers->through(fn (Customer $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'dni' => $c->dni,
                'email' => $c->email,
                'phone' => $c->phone,
                'is_anonymous' => $c->is_anonymous,
                'created_at' => $c->created_at->toIso8601String(),
                'vehicles_count' => $c->vehicles->count(),
                'conversations_count' => $c->conversations->count(),
                'polizas_vigentes_count' => $c->risks
                    ->flatMap->polizas
                    ->where('estado', PolizaEstado::Vigente)
                    ->count(),
            ]),
            'filters' => ['search' => $search, 'per_page' => $perPage],
        ]);
    }

    public function show(Customer $customer): Response|RedirectResponse
    {
        $customer = $this->customerRepository->findWithRelations(
            id: $customer->id,
            relations: ['pas', 'vehicles', 'conversations.quotes', 'risks.polizas.documents']
        );

        if (! $customer instanceof Customer) {
            return redirect()->route('customers.index')->with('error', 'Cliente no encontrado');
        }

        $polizas = $this->mapPolizas($customer);
        $latestSession = $this->latestCheckoutSession($customer);

        return Inertia::render('Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'dni' => $customer->dni,
                'document_type_id' => $customer->document_type_id,
                'person_type_id' => $customer->person_type_id,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'birthdate' => $customer->birthdate?->toDateString(),
                'sex_id' => $customer->sex_id,
                'tax_condition_id' => $customer->tax_condition_id,
                'domicilio' => [
                    'calle' => $customer->domicilio_calle,
                    'numero' => $customer->domicilio_numero,
                    'cp' => $customer->domicilio_cp,
                    'provincia' => $customer->domicilio_provincia,
                    'localidad' => $customer->domicilio_localidad,
                ],
                'is_anonymous' => $customer->is_anonymous,
                'completed_at' => $customer->completed_at?->toIso8601String(),
                'created_at' => $customer->created_at->toIso8601String(),
                'notes' => $customer->metadata['notes'] ?? '',
                'pas' => $customer->pas ? ['id' => $customer->pas->id, 'name' => $customer->pas->name] : null,
                'vehicles' => $customer->vehicles->map(fn ($v): array => [
                    'id' => $v->id,
                    'patente' => $v->patente,
                    'marca' => $v->marca,
                    'modelo' => $v->modelo,
                    'year' => $v->year,
                    'uso' => $v->uso,
                    'is_complete' => $v->is_complete,
                ]),
                'polizas' => $polizas,
                'cotizaciones' => $this->mapCotizaciones($customer),
                'conversations' => $customer->conversations->map(fn ($c): array => [
                    'id' => $c->id,
                    'external_conversation_id' => $c->external_conversation_id,
                    'last_message_at' => $c->last_message_at,
                    'created_at' => $c->created_at->toIso8601String(),
                ])->values(),
                'resumen' => $this->resumenCartera($polizas, $customer),
                'vencimientos' => $this->vencimientos($polizas),
                'checkout' => $this->mapCheckoutSession($latestSession),
                'divergencias' => $this->divergencias($customer, $latestSession),
                'timeline' => $this->timeline($customer),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Customers/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateCustomer($request, null);

        // El alta identifica antes de crear, como toda puerta por la que entra un cliente: si
        // ya existía por otra vía (WhatsApp por teléfono, ingesta de pólizas por documento) se
        // edita esa fila en vez de duplicarla.
        $existing = $this->identifyExisting($validated);

        // El alta crea con los identificadores base; la consolidación registra los
        // campos de holder con provenance/audit de fuente admin.
        $customer = $existing ?? $this->customerRepository->create([
            'name' => $validated['name'] ?? null,
            'dni' => $validated['dni'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
        ]);

        $this->consolidation->apply($customer, $this->holderPayload($validated), 'admin', $request->user()?->id);

        return redirect()->route('customers.show', $customer)
            ->with('flash', [
                'success' => $existing instanceof Customer
                    ? 'Este cliente ya existía: se actualizó la ficha existente en vez de duplicarla.'
                    : 'Cliente creado.',
            ]);
    }

    public function edit(Customer $customer, VisredCatalogService $catalog): Response
    {
        return Inertia::render('Customers/Edit', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'dni' => $customer->dni,
                'document_type_id' => $customer->document_type_id,
                'person_type_id' => $customer->person_type_id,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'birthdate' => $customer->birthdate?->toDateString(),
                'sex_id' => $customer->sex_id,
                'tax_condition_id' => $customer->tax_condition_id,
                'domicilio_calle' => $customer->domicilio_calle,
                'domicilio_numero' => $customer->domicilio_numero,
                'domicilio_cp' => $customer->domicilio_cp,
                'domicilio_provincia' => $customer->domicilio_provincia,
                'domicilio_localidad' => $customer->domicilio_localidad,
                'pas_id' => $customer->pas_id,
                'notes' => $customer->metadata['notes'] ?? '',
            ],
            'pasUsers' => User::pas()->orderBy('name')->get(['id', 'name']),
            'taxConditions' => $catalog->taxConditions(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $this->validateCustomer($request, $customer);

        // PAS y notas son atributos de gestión, no de identidad: se escriben directo.
        $customer->pas_id = $validated['pas_id'] ?? null;
        $customer->metadata = array_merge($customer->metadata ?? [], [
            'notes' => $validated['notes'] ?? '',
        ]);
        $customer->save();

        // Identidad → consolidación con provenance/audit de fuente admin.
        $this->consolidation->apply($customer, $this->holderPayload($validated), 'admin', $request->user()?->id);

        return redirect()->route('customers.show', $customer)
            ->with('flash', ['success' => 'Cliente actualizado.']);
    }

    /**
     * Resuelve una divergencia (Customer vs checkout) aplicando el valor elegido como
     * edición admin (queda auditada).
     */
    public function resolveDivergence(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'string', Rule::in(self::HOLDER_FIELDS)],
            'value' => ['required', 'string', 'max:255'],
        ]);

        $this->consolidation->apply(
            $customer,
            [$validated['field'] => $validated['value']],
            'admin',
            $request->user()?->id,
        );

        return back()->with('flash', ['success' => 'Divergencia resuelta.']);
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $hasVigente = $customer->risks()
            ->whereHas('polizas', fn ($q) => $q->where('estado', PolizaEstado::Vigente))
            ->exists();

        if ($hasVigente) {
            return back()->with('flash', [
                'error' => 'No se puede eliminar: el cliente tiene una póliza vigente.',
            ]);
        }

        $customer->delete();

        return redirect()->route('customers.index')
            ->with('flash', ['success' => 'Cliente eliminado.']);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Cliente ya existente detrás de los datos del formulario, o null si es alguien nuevo.
     * La búsqueda va por {@see CustomerIdentificationService}, igual que el resto de las
     * puertas. Las reglas `unique` de dni/email ya frenan el duplicado exacto; esto cubre lo
     * que se les escapa: el mismo teléfono que un cliente creado por WhatsApp, y el CUIL/CUIT
     * de alguien cargado antes con su DNI pelado.
     *
     * @param  array<string, mixed>  $validated
     */
    private function identifyExisting(array $validated): ?Customer
    {
        $porDocumento = empty($validated['dni']) ? null : $this->identification->findCustomer(
            'dni',
            (string) $validated['dni'],
            $validated['document_type_id'] ?? null,
            $validated['person_type_id'] ?? null,
        );

        if ($porDocumento instanceof Customer) {
            return $porDocumento;
        }

        $porEmail = empty($validated['email'])
            ? null
            : $this->identification->findCustomer('email', (string) $validated['email']);

        if ($porEmail instanceof Customer) {
            return $porEmail;
        }

        return empty($validated['phone'])
            ? null
            : $this->identification->findCustomer('phone', (string) $validated['phone']);
    }

    private function validateCustomer(Request $request, ?Customer $customer): array
    {
        $ignoreId = $customer?->id;

        $validated = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:255',
            'dni' => ['nullable', 'string', 'max:20', Rule::unique('customers', 'dni')->ignore($ignoreId)],
            'document_type_id' => 'nullable|string|max:50',
            'person_type_id' => 'nullable|string|max:50',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($ignoreId)],
            'phone' => 'nullable|string|max:30',
            'birthdate' => 'nullable|date',
            'sex_id' => 'nullable|string|max:20',
            'tax_condition_id' => 'nullable|string|max:50',
            'domicilio_calle' => 'nullable|string|max:255',
            'domicilio_numero' => 'nullable|string|max:20',
            'domicilio_cp' => 'nullable|string|max:10',
            'domicilio_provincia' => 'nullable|string|max:100',
            'domicilio_localidad' => 'nullable|string|max:100',
            'pas_id' => ['nullable', Rule::exists('users', 'id')],
            'notes' => 'nullable|string|max:5000',
        ]);

        // `name` ↔ splits: si vino solo `name` (alta simple), lo partimos para que la
        // consolidación lo registre como first/last; si vinieron los splits, derivamos `name`.
        if (! empty($validated['name']) && empty($validated['first_name']) && empty($validated['last_name'])) {
            $parts = preg_split('/\s+/', trim((string) $validated['name']), 2);
            $validated['first_name'] = $parts[0] ?? null;
            $validated['last_name'] = $parts[1] ?? null;
        }
        $derivedName = trim(($validated['first_name'] ?? '').' '.($validated['last_name'] ?? ''));
        if (empty($validated['name']) && $derivedName !== '') {
            $validated['name'] = $derivedName;
        }

        if (empty($validated['dni']) && empty($validated['email']) && empty($validated['phone'])) {
            throw ValidationException::withMessages([
                'dni' => 'Ingresá al menos un identificador: DNI, email o teléfono.',
            ]);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function holderPayload(array $validated): array
    {
        $payload = [];
        foreach (self::HOLDER_FIELDS as $field) {
            if (! array_key_exists($field, $validated) || $validated[$field] === null || $validated[$field] === '') {
                continue;
            }
            $value = $validated[$field];
            if ($field === 'email') {
                $value = mb_strtolower(trim((string) $value));
            }
            if ($field === 'phone') {
                $value = $this->customerRepository->normalizePhone((string) $value);
            }
            $payload[$field] = $value;
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapPolizas(Customer $customer): array
    {
        $polizas = [];
        foreach ($customer->risks as $risk) {
            foreach ($risk->polizas as $p) {
                $polizas[] = [
                    'id' => $p->id,
                    'numero' => $p->numero,
                    'company' => $p->company,
                    'coverage' => $p->coverage,
                    'estado' => $p->estado->value,
                    'cuota' => $p->cuota,
                    'cuota_due' => $p->cuota_due?->toDateString(),
                    'vigencia' => $p->vigencia?->toDateString(),
                    'emitida_en' => $p->emitida_en?->toDateString(),
                    'patente' => $risk->asset->metadata['patente'] ?? null,
                    'label' => $risk->label,
                    'documents' => $p->documents->map(fn (PolicyDocument $d): array => [
                        'id' => $d->id,
                        'kind' => $d->kind->value,
                        'label' => $d->label,
                        // Firmada al vuelo: el bucket es privado.
                        'storage_url' => Storage::disk('r2')->temporaryUrl($d->storage_path, now()->addMinutes(15)),
                        'visible_to_client' => $d->visible_to_client,
                    ])->values(),
                ];
            }
        }

        return $polizas;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapCotizaciones(Customer $customer): array
    {
        return $customer->conversations
            ->flatMap(fn ($c) => $c->quotes)
            ->sortByDesc('created_at')
            ->map(fn ($q): array => [
                'id' => $q->id,
                'status' => $q->status,
                'created_at' => $q->created_at->toIso8601String(),
                'expires_at' => $q->expires_at?->toIso8601String(),
                'alternativas_count' => $q->alternatives()->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $polizas
     * @return array<string, mixed>
     */
    private function resumenCartera(array $polizas, Customer $customer): array
    {
        $vigentes = array_filter($polizas, fn ($p): bool => $p['estado'] === PolizaEstado::Vigente->value);

        return [
            'polizas_vigentes' => count($vigentes),
            'prima_mensual' => array_sum(array_map(fn ($p): float => (float) ($p['cuota'] ?? 0), $vigentes)),
            'cotizaciones_abiertas' => $customer->conversations
                ->flatMap(fn ($c) => $c->quotes)
                ->whereIn('status', ['pending', 'processed', 'checkout_pending'])
                ->count(),
            'cliente_desde' => $customer->created_at->toIso8601String(),
        ];
    }

    /**
     * Vencimientos accionables: cuotas por cobrar y renovaciones próximas de las
     * pólizas vigentes, ordenadas por urgencia.
     *
     * @param  list<array<string, mixed>>  $polizas
     * @return list<array<string, mixed>>
     */
    private function vencimientos(array $polizas): array
    {
        $items = [];
        foreach ($polizas as $p) {
            if ($p['estado'] !== PolizaEstado::Vigente->value) {
                continue;
            }
            foreach (['cuota' => $p['cuota_due'], 'vigencia' => $p['vigencia']] as $tipo => $fecha) {
                if ($fecha === null) {
                    continue;
                }
                $items[] = [
                    'poliza_id' => $p['id'],
                    'tipo' => $tipo,
                    'fecha' => $fecha,
                    'dias_restantes' => (int) Carbon::today()->diffInDays(Carbon::parse($fecha), false),
                    'numero' => $p['numero'],
                    'company' => $p['company'],
                ];
            }
        }

        usort($items, fn ($a, $b): int => $a['dias_restantes'] <=> $b['dias_restantes']);

        return $items;
    }

    private function latestCheckoutSession(Customer $customer): ?CheckoutSession
    {
        return CheckoutSession::whereHas(
            'quote.conversation',
            fn ($q) => $q->where('customer_id', $customer->id)
        )->latest('submitted_at')->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapCheckoutSession(?CheckoutSession $session): ?array
    {
        if (! $session instanceof CheckoutSession) {
            return null;
        }

        return [
            'id' => $session->id,
            'status' => $session->status,
            'submitted_at' => $session->submitted_at?->toIso8601String(),
            'nombre' => trim(($session->first_name ?? '').' '.($session->last_name ?? '')),
            'dni' => $session->dni,
            'email' => $session->email,
            'telefono' => $session->telefono,
            'domicilio' => trim(implode(' ', array_filter([
                $session->domicilio_calle,
                $session->domicilio_numero,
                $session->domicilio_cp ? "(CP {$session->domicilio_cp})" : null,
                $session->domicilio_localidad,
                $session->domicilio_provincia,
            ]))),
        ];
    }

    /**
     * Diferencias entre el Customer canónico y la última declaración del checkout, para
     * que el productor las resuelva a mano.
     *
     * @return list<array{field: string, label: string, customer: string|null, checkout: string}>
     */
    private function divergencias(Customer $customer, ?CheckoutSession $session): array
    {
        if (! $session instanceof CheckoutSession) {
            return [];
        }

        $map = [
            'first_name' => ['Nombre', $session->first_name],
            'last_name' => ['Apellido', $session->last_name],
            'dni' => ['DNI', $session->dni],
            'birthdate' => ['Nacimiento', $session->birthdate?->toDateString()],
            'sex_id' => ['Sexo', $session->sex_id],
            'tax_condition_id' => ['Cond. fiscal', $session->tax_condition_id],
            'email' => ['Email', $session->email ? mb_strtolower($session->email) : null],
            'domicilio_calle' => ['Calle', $session->domicilio_calle],
            'domicilio_numero' => ['Número', $session->domicilio_numero],
            'domicilio_cp' => ['CP', $session->domicilio_cp],
            'domicilio_provincia' => ['Provincia', $session->domicilio_provincia],
            'domicilio_localidad' => ['Localidad', $session->domicilio_localidad],
        ];

        $out = [];
        foreach ($map as $field => [$label, $checkoutValue]) {
            if ($checkoutValue === null || $checkoutValue === '') {
                continue;
            }
            if ($field === 'birthdate') {
                $currentStr = $customer->birthdate?->toDateString();
            } else {
                $current = $customer->{$field};
                $currentStr = $current === null ? null : (string) $current;
            }
            if ($field === 'email' && $currentStr !== null) {
                $currentStr = mb_strtolower($currentStr);
            }
            if ($currentStr !== null && $currentStr !== '' && $currentStr !== (string) $checkoutValue) {
                $out[] = [
                    'field' => $field,
                    'label' => $label,
                    'customer' => $currentStr,
                    'checkout' => (string) $checkoutValue,
                ];
            }
        }

        return $out;
    }

    /**
     * Línea de tiempo unificada (desc) de hechos del cliente.
     *
     * @return list<array{tipo: string, fecha: string, label: string}>
     */
    private function timeline(Customer $customer): array
    {
        $events = [];
        $events[] = ['tipo' => 'alta', 'fecha' => $customer->created_at->toIso8601String(), 'label' => 'Alta del cliente'];

        foreach ($customer->conversations as $c) {
            foreach ($c->quotes as $q) {
                $events[] = ['tipo' => 'cotizacion', 'fecha' => $q->created_at->toIso8601String(), 'label' => "Cotización #{$q->id} ({$q->status})"];
            }
            if ($c->last_message_at !== null) {
                $events[] = ['tipo' => 'mensaje', 'fecha' => $c->last_message_at->toIso8601String(), 'label' => 'Última actividad en conversación'];
            }
        }

        foreach ($customer->risks as $risk) {
            foreach ($risk->polizas as $p) {
                if ($p->emitida_en) {
                    $events[] = ['tipo' => 'poliza', 'fecha' => $p->emitida_en->toIso8601String(), 'label' => 'Póliza emitida'.($p->numero ? " {$p->numero}" : '')];
                }
            }
        }

        usort($events, fn ($a, $b): int => strcmp($b['fecha'], $a['fecha']));

        return $events;
    }
}
