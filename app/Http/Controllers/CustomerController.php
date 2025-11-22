<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use App\Repositories\CustomerRepository;

class CustomerController extends Controller
{
    public function __construct(
        protected CustomerRepository $customerRepository,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->input('search', '');
        $perPage = $request->input('per_page', 15);

        // Get customers with their related data
        $customers = $this->customerRepository->getAllWithRelations(
            ['vehicles', 'conversations'],
            $search,
            $perPage
        );

        return view('customers.index', [
            'customers' => $customers,
            'search' => $search,
            'perPage' => $perPage,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer)
    {
        $customer = $this->customerRepository->findWithRelations(
            id: $customer->id,
            relations: ['vehicles', 'conversations']
        );

        if (!$customer) {
            return redirect()
                ->route('customers.index')
                ->with('error', 'Cliente no encontrado');
        }

        return view('customers.show', [
            'customer' => $customer,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Customer $customer)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Customer $customer)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Customer $customer)
    {
        //
    }
}
