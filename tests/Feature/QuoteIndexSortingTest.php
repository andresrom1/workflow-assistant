<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QuoteIndexSortingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function it_sorts_quotes_by_vehiculo(): void
    {
        $snapshotA = RiskSnapshot::factory()->create([
            'marca' => 'Fiat',
            'modelo' => 'Cronos',
        ]);
        $snapshotZ = RiskSnapshot::factory()->create([
            'marca' => 'Volkswagen',
            'modelo' => 'Gol Trend',
        ]);

        $quoteZ = Quote::factory()->create([
            'risk_snapshot_id' => $snapshotZ->id,
            'conversation_id' => Conversation::factory()->create(['customer_id' => Customer::factory()->create()->id]),
        ]);
        $quoteA = Quote::factory()->create([
            'risk_snapshot_id' => $snapshotA->id,
            'conversation_id' => Conversation::factory()->create(['customer_id' => Customer::factory()->create()->id]),
        ]);

        $this->actingAs($this->user)
            ->get(route('quotes.index', ['sort' => 'vehiculo', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Quotes/Index')
                ->where('quotes.data.0.id', $quoteA->id)
                ->where('quotes.data.1.id', $quoteZ->id)
            );
    }

    #[Test]
    public function it_ignores_invalid_sort_parameters(): void
    {
        Quote::factory()->count(3)->create([
            'conversation_id' => Conversation::factory()->create(['customer_id' => Customer::factory()->create()->id]),
        ]);

        $this->actingAs($this->user)
            ->get(route('quotes.index', ['sort' => 'injected', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Quotes/Index')
                ->has('quotes.data', 3)
            );
    }
}
