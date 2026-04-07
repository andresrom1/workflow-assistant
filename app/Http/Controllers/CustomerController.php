<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Repositories\CustomerRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            relations: ['vehicles', 'conversations']
        );

        if (! $customer instanceof Customer) {
            return redirect()->route('customers.index')->with('error', 'Cliente no encontrado');
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
            ],
        ]);
    }

    // Métodos sin implementar — se dejan vacíos
    public function create() {}

    public function store(Request $request) {}

    public function edit(Customer $customer) {}

    public function update(Request $request, Customer $customer) {}

    public function destroy(Customer $customer) {}
}
