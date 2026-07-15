<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PolizaIndexSortingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function it_sorts_polizas_by_numero_ascending(): void
    {
        $first = Poliza::factory()->create(['numero' => 'POL-1000']);
        $second = Poliza::factory()->create(['numero' => 'POL-2000']);

        $this->actingAs($this->user)
            ->get(route('polizas.index', ['sort' => 'numero', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Polizas/Index')
                ->where('polizas.data.0.id', $first->id)
                ->where('polizas.data.1.id', $second->id)
            );
    }

    #[Test]
    public function it_sorts_polizas_by_cliente_name(): void
    {
        $customerA = Customer::factory()->create(['name' => 'Ana García']);
        $customerZ = Customer::factory()->create(['name' => 'Zoe Martínez']);

        $riskA = Risk::factory()->create(['customer_id' => $customerA->id]);
        $riskZ = Risk::factory()->create(['customer_id' => $customerZ->id]);

        $polizaZ = Poliza::factory()->create(['risk_id' => $riskZ->id]);
        $polizaA = Poliza::factory()->create(['risk_id' => $riskA->id]);

        $this->actingAs($this->user)
            ->get(route('polizas.index', ['sort' => 'cliente', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('polizas.data.0.id', $polizaA->id)
                ->where('polizas.data.1.id', $polizaZ->id)
            );
    }

    #[Test]
    public function it_sorts_polizas_by_patente(): void
    {
        $riskA = Risk::factory()->create(['metadata' => ['patente' => 'AAA000']]);
        $riskZ = Risk::factory()->create(['metadata' => ['patente' => 'ZZZ999']]);

        $polizaZ = Poliza::factory()->create(['risk_id' => $riskZ->id]);
        $polizaA = Poliza::factory()->create(['risk_id' => $riskA->id]);

        $this->actingAs($this->user)
            ->get(route('polizas.index', ['sort' => 'patente', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('polizas.data.0.id', $polizaA->id)
                ->where('polizas.data.1.id', $polizaZ->id)
            );
    }

    #[Test]
    public function it_ignores_invalid_sort_parameters(): void
    {
        Poliza::factory()->count(3)->create();

        $this->actingAs($this->user)
            ->get(route('polizas.index', ['sort' => 'injected', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Polizas/Index')
                ->has('polizas.data', 3)
            );
    }
}
