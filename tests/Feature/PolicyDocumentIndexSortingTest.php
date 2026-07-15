<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PolicyDocumentIndexSortingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function it_sorts_polizas_by_last_document_at_descending_by_default(): void
    {
        $polizaOlder = Poliza::factory()->create();
        $polizaNewer = Poliza::factory()->create();

        PolicyDocument::factory()->create([
            'poliza_id' => $polizaOlder->id,
            'captured_at' => now()->subDays(2),
        ]);

        PolicyDocument::factory()->create([
            'poliza_id' => $polizaNewer->id,
            'captured_at' => now()->subDay(),
        ]);

        $this->actingAs($this->user)
            ->get(route('policy-documents.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PolicyDocuments/Index')
                ->where('polizas.data.0.id', $polizaNewer->id)
                ->where('polizas.data.1.id', $polizaOlder->id)
            );
    }

    #[Test]
    public function it_sorts_polizas_by_numero(): void
    {
        $polizaFirst = Poliza::factory()->create(['numero' => 'POL-1000']);
        $polizaSecond = Poliza::factory()->create(['numero' => 'POL-2000']);

        PolicyDocument::factory()->create(['poliza_id' => $polizaFirst->id]);
        PolicyDocument::factory()->create(['poliza_id' => $polizaSecond->id]);

        $this->actingAs($this->user)
            ->get(route('policy-documents.index', ['sort' => 'numero', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('polizas.data.0.numero', 'POL-1000')
                ->where('polizas.data.1.numero', 'POL-2000')
            );
    }

    #[Test]
    public function it_sorts_polizas_by_cliente_name(): void
    {
        $customerA = Customer::factory()->create(['name' => 'Ana García']);
        $customerZ = Customer::factory()->create(['name' => 'Zoe Martínez']);

        $riskA = Risk::factory()->create(['customer_id' => $customerA->id]);
        $riskZ = Risk::factory()->create(['customer_id' => $customerZ->id]);

        $polizaA = Poliza::factory()->create(['risk_id' => $riskA->id]);
        $polizaZ = Poliza::factory()->create(['risk_id' => $riskZ->id]);

        PolicyDocument::factory()->create(['poliza_id' => $polizaA->id]);
        PolicyDocument::factory()->create(['poliza_id' => $polizaZ->id]);

        $this->actingAs($this->user)
            ->get(route('policy-documents.index', ['sort' => 'cliente', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('polizas.data.0.cliente', 'Ana García')
                ->where('polizas.data.1.cliente', 'Zoe Martínez')
            );
    }

    #[Test]
    public function it_ignores_invalid_sort_parameters(): void
    {
        PolicyDocument::factory()->count(3)->create();

        $this->actingAs($this->user)
            ->get(route('policy-documents.index', ['sort' => 'injected', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PolicyDocuments/Index')
                ->has('polizas.data', 3)
            );
    }
}
