<?php

namespace App\Http\Controllers;

use App\Enums\PolizaEstado;
use App\Models\Customer;
use App\Repositories\CustomerRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function __construct(
        protected CustomerRepository $customerRepository,
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->input('search', '');
        $perPage = $request->input('per_page', 15);

        $customers = $this->customerRepository->getAllWithRelations(
            ['vehicles', 'conversations'],
            $search,
            $perPage
        );

        return Inertia::render('Customers/Index', [
            // Transformamos explícitamente para que Vue reciba
            // vehicles_count y conversations_count como integers,
            // no como arrays de relaciones completas.
            'customers' => $customers->through(fn ($c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'dni' => $c->dni,
                'email' => $c->email,
                'phone' => $c->phone,
                'created_at' => $c->created_at->toIso8601String(),
                'vehicles_count' => $c->vehicles->count(),
                'conversations_count' => $c->conversations->count(),
            ]),
            'filters' => ['search' => $search, 'per_page' => $perPage],
        ]);
    }

    public function show(Customer $customer): Response|RedirectResponse
    {
        $customer = $this->customerRepository->findWithRelations(
            id: $customer->id,
            relations: ['vehicles', 'conversations', 'risks.polizas']
        );

        if (! $customer instanceof Customer) {
            return redirect()->route('customers.index')->with('error', 'Cliente no encontrado');
        }

        $polizas = [];
        foreach ($customer->risks as $risk) {
            foreach ($risk->polizas as $p) {
                $polizas[] = [
                    'id' => $p->id,
                    'numero' => $p->numero,
                    'company' => $p->company,
                    'coverage' => $p->coverage,
                    'estado' => $p->estado->value,
                    'vigencia' => $p->vigencia?->toDateString(),
                    'patente' => $risk->metadata['patente'] ?? null,
                    'label' => $risk->label,
                ];
            }
        }

        return Inertia::render('Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'dni' => $customer->dni,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'created_at' => $customer->created_at->toIso8601String(),
                'vehicles' => $customer->vehicles->map(fn ($v): array => [
                    'id' => $v->id,
                    'patente' => $v->patente,
                    'marca' => $v->marca,
                    'modelo' => $v->modelo,
                    'year' => $v->year,
                    'uso' => $v->uso,
                    'is_complete' => $v->is_complete,
                ]),
                'conversations' => $customer->conversations->map(fn ($c): array => [
                    'id' => $c->id,
                    'external_conversation_id' => $c->external_conversation_id,
                    'last_message_at' => $c->last_message_at,
                    'created_at' => $c->created_at->toIso8601String(),
                ]),
                'polizas' => $polizas,
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

        $customer = $this->customerRepository->create($validated);

        return redirect()->route('customers.show', $customer)
            ->with('flash', ['success' => 'Cliente creado.']);
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('Customers/Edit', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'dni' => $customer->dni,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ],
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $validated = $this->validateCustomer($request, $customer);

        $this->customerRepository->update($customer, $validated);

        return redirect()->route('customers.show', $customer)
            ->with('flash', ['success' => 'Cliente actualizado.']);
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
    private function validateCustomer(Request $request, ?Customer $customer): array
    {
        $ignoreId = $customer?->id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'dni' => ['nullable', 'string', 'max:20', Rule::unique('customers', 'dni')->ignore($ignoreId)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($ignoreId)],
            'phone' => 'nullable|string|max:30',
        ]);

        if (empty($validated['dni']) && empty($validated['email']) && empty($validated['phone'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'dni' => 'Ingresá al menos un identificador: DNI, email o teléfono.',
            ]);
        }

        return array_filter(
            $validated,
            fn ($value): bool => $value !== null && $value !== '',
        );
    }
}
