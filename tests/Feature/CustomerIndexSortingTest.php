<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerIndexSortingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function it_sorts_customers_by_name_ascending(): void
    {
        $ana = Customer::factory()->create(['name' => 'Ana García']);
        $juan = Customer::factory()->create(['name' => 'Juan Pérez']);
        $zoe = Customer::factory()->create(['name' => 'Zoe Martínez']);

        $this->actingAs($this->user)
            ->get(route('customers.index', ['sort' => 'name', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customers/Index')
                ->where('customers.data.0.id', $ana->id)
                ->where('customers.data.1.id', $juan->id)
                ->where('customers.data.2.id', $zoe->id)
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'asc')
            );
    }

    #[Test]
    public function it_sorts_customers_by_name_descending(): void
    {
        $ana = Customer::factory()->create(['name' => 'Ana García']);
        $juan = Customer::factory()->create(['name' => 'Juan Pérez']);
        $zoe = Customer::factory()->create(['name' => 'Zoe Martínez']);

        $this->actingAs($this->user)
            ->get(route('customers.index', ['sort' => 'name', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('customers.data.0.id', $zoe->id)
                ->where('customers.data.1.id', $juan->id)
                ->where('customers.data.2.id', $ana->id)
            );
    }

    #[Test]
    public function it_sorts_customers_by_created_at(): void
    {
        $older = Customer::factory()->create(['created_at' => now()->subDays(2)]);
        $newer = Customer::factory()->create(['created_at' => now()->subDay()]);

        $this->actingAs($this->user)
            ->get(route('customers.index', ['sort' => 'created_at', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('customers.data.0.id', $older->id)
                ->where('customers.data.1.id', $newer->id)
            );
    }

    #[Test]
    public function it_ignores_invalid_sort_parameters(): void
    {
        Customer::factory()->count(3)->create();

        $this->actingAs($this->user)
            ->get(route('customers.index', ['sort' => 'injected', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customers/Index')
                ->has('customers.data', 3)
            );
    }

    #[Test]
    public function it_preserves_sort_when_searching(): void
    {
        $ana = Customer::factory()->create(['name' => 'Ana García']);
        Customer::factory()->create(['name' => 'Pedro López']);

        $this->actingAs($this->user)
            ->get(route('customers.index', [
                'search' => 'Ana',
                'sort' => 'name',
                'direction' => 'asc',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.id', $ana->id)
                ->where('filters.search', 'Ana')
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'asc')
            );
    }

    #[Test]
    public function it_sorts_customers_by_vehicles_count(): void
    {
        $customerFew = Customer::factory()->create();
        $customerMany = Customer::factory()->create();

        Vehicle::factory()->count(3)->create(['customer_id' => $customerMany->id]);
        Vehicle::factory()->create(['customer_id' => $customerFew->id]);

        $this->actingAs($this->user)
            ->get(route('customers.index', ['sort' => 'vehicles_count', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('customers.data.0.id', $customerMany->id)
                ->where('customers.data.1.id', $customerFew->id)
            );
    }

    #[Test]
    public function it_sorts_customers_by_conversations_count(): void
    {
        $customerFew = Customer::factory()->create();
        $customerMany = Customer::factory()->create();

        Conversation::factory()->count(3)->create(['customer_id' => $customerMany->id]);
        Conversation::factory()->create(['customer_id' => $customerFew->id]);

        $this->actingAs($this->user)
            ->get(route('customers.index', ['sort' => 'conversations_count', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('customers.data.0.id', $customerMany->id)
                ->where('customers.data.1.id', $customerFew->id)
            );
    }

    #[Test]
    public function it_sorts_customers_by_polizas_vigentes_count(): void
    {
        $customerFew = Customer::factory()->create();
        $customerMany = Customer::factory()->create();

        $riskMany = Risk::factory()->create(['customer_id' => $customerMany->id]);
        $riskFew = Risk::factory()->create(['customer_id' => $customerFew->id]);

        Poliza::factory()->count(3)->create(['risk_id' => $riskMany->id]);
        Poliza::factory()->create(['risk_id' => $riskFew->id]);

        $this->actingAs($this->user)
            ->get(route('customers.index', ['sort' => 'polizas_vigentes_count', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('customers.data.0.id', $customerMany->id)
                ->where('customers.data.1.id', $customerFew->id)
            );
    }
}
