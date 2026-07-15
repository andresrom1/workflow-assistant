<?php

namespace Tests\Feature;

use App\Models\CoverageDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CoverageDocumentIndexSortingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function it_sorts_coverage_documents_by_company_name_ascending(): void
    {
        $docA = CoverageDocument::factory()->create(['company_name' => 'Aseguradora Alfa']);
        $docZ = CoverageDocument::factory()->create(['company_name' => 'Zurich Seguros']);

        $this->actingAs($this->user)
            ->get(route('coverage-documents.index', ['sort' => 'company_name', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('CoverageDocuments/Index')
                ->where('documents.data.0.id', $docA->id)
                ->where('documents.data.1.id', $docZ->id)
            );
    }

    #[Test]
    public function it_sorts_coverage_documents_by_chunks_count(): void
    {
        $docFew = CoverageDocument::factory()->create();
        $docMany = CoverageDocument::factory()->create();

        $embedding = '['.implode(',', array_fill(0, 1536, '0.0')).']';

        foreach (range(1, 3) as $index) {
            $docMany->chunks()->create([
                'chunk_index' => $index,
                'content' => "Chunk {$index}",
                'embedding' => $embedding,
            ]);
        }

        $docFew->chunks()->create([
            'chunk_index' => 1,
            'content' => 'Single chunk',
            'embedding' => $embedding,
        ]);

        $this->actingAs($this->user)
            ->get(route('coverage-documents.index', ['sort' => 'chunks_count', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('documents.data.0.id', $docMany->id)
                ->where('documents.data.1.id', $docFew->id)
            );
    }

    #[Test]
    public function it_sorts_coverage_documents_by_updated_at(): void
    {
        $older = CoverageDocument::factory()->create(['updated_at' => now()->subDays(2)]);
        $newer = CoverageDocument::factory()->create(['updated_at' => now()->subDay()]);

        $this->actingAs($this->user)
            ->get(route('coverage-documents.index', ['sort' => 'updated_at', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('CoverageDocuments/Index')
                ->where('documents.data.0.id', $newer->id)
                ->where('documents.data.1.id', $older->id)
            );
    }

    #[Test]
    public function it_ignores_invalid_sort_parameters(): void
    {
        CoverageDocument::factory()->count(3)->create();

        $this->actingAs($this->user)
            ->get(route('coverage-documents.index', ['sort' => 'injected', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('CoverageDocuments/Index')
                ->has('documents.data', 3)
            );
    }
}
