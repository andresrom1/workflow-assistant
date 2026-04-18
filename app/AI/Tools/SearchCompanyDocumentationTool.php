<?php

namespace App\AI\Tools;

use App\Models\CoverageChunk;
use App\Models\CoverageDocument;
use App\Traits\ConditionalLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;
use Pgvector\Laravel\Distance;

class SearchCompanyDocumentationTool implements Tool
{
    use ConditionalLogger;

    public function description(): string
    {
        return 'Busca en la documentacion oficial de una compania de seguros. '
            .'Devuelve los fragmentos mas relevantes sobre el tema consultado. '
            .'Usala cuando necesites detalles especificos de coberturas, limites, condiciones o exclusiones.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Consulta de busqueda. Ej: "limite ruedas por evento robo parcial", "asistencia grua kilometros".')
                ->required(),
            'company_slug' => $schema->string()
                ->description('Slug de la compania. Ej: "san-cristobal", "triunfo", "federacion-patronal".')
                ->required(),
            'top_k' => $schema->string()
                ->description('Cantidad de fragmentos a devolver (1-10). Default: "4".')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $this->logToolCall($request->all());

        /** @var string $query */
        $query = $request['query'];
        /** @var string $companySlug */
        $companySlug = $request['company_slug'];
        $topK = min(max((int) ($request['top_k'] ?? 4), 1), 10);

        // 1. Generate embedding for the query
        $response = Embeddings::for([$query])->dimensions(1536)->generate();
        $queryEmbedding = $response->embeddings[0];

        // 2. Search pgvector — cosine distance, filtered by company.
        // Intentionally avoided JOIN: nearestNeighbors uses SELECT * internally,
        // which would cause a column collision between coverage_chunks.id and
        // coverage_documents.id, causing find() to re-fetch the wrong chunk.
        $documentIds = CoverageDocument::where('company_slug', $companySlug)
            ->where('is_active', true)
            ->pluck('id');

        $chunks = CoverageChunk::query()
            ->nearestNeighbors('embedding', $queryEmbedding, Distance::Cosine)
            ->whereIn('coverage_document_id', $documentIds)
            ->with('coverageDocument')
            ->take($topK)
            ->get();

        if ($chunks->isEmpty()) {
            return json_encode([
                'found' => false,
                'message' => "No hay documentacion cargada para la compania '{$companySlug}'.",
            ], JSON_THROW_ON_ERROR);
        }

        // 3. Format results for the Expert Agent
        $results = [];
        $companyName = '';

        foreach ($chunks as $chunk) {
            $doc = $chunk->coverageDocument;
            $meta = $chunk->metadata;

            if ($companyName === '') {
                $companyName = $doc->company_name;
            }

            $results[] = [
                'document_type' => $doc->document_type,
                'section' => $meta['section'] ?? null,
                'content' => $chunk->content,
            ];
        }

        return json_encode([
            'found' => true,
            'company' => $companyName,
            'fragments_count' => count($results),
            'fragments' => $results,
        ], JSON_THROW_ON_ERROR);
    }
}
