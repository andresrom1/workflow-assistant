<?php

namespace App\Jobs;

use App\Models\CoverageDocument;
use App\Services\ChunkAndEmbedService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Document;

use function Laravel\Ai\agent;

class ExtractCoverageDocumentText implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public CoverageDocument $document,
    ) {}

    public function handle(): void
    {
        Log::info('ExtractCoverageDocumentText: starting', [
            'document_id' => $this->document->id,
            'filename' => $this->document->original_filename,
            'provider' => $this->document->extraction_provider,
        ]);

        $response = agent(instructions: $this->extractionPrompt())
            ->prompt(
                'Transcribi el contenido completo de este documento.',
                attachments: [
                    Document::fromStorage(
                        $this->document->storage_path,
                        disk: $this->document->storage_disk,
                    ),
                ],
                provider: $this->resolveProvider(),
            );

        $this->document->update([
            'extracted_content' => $response->text,
            'extraction_status' => 'completed',
        ]);

        Log::info('ExtractCoverageDocumentText: completed', [
            'document_id' => $this->document->id,
            'content_length' => mb_strlen($response->text),
        ]);

        // Auto-trigger chunking (synchronous — runs in same job)
        app(ChunkAndEmbedService::class)->execute($this->document);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExtractCoverageDocumentText: failed', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        $this->document->update([
            'extraction_status' => 'failed',
            'extraction_mode' => 'manual',
        ]);
    }

    private function resolveProvider(): Lab
    {
        return match ($this->document->extraction_provider) {
            'anthropic' => Lab::Anthropic,
            'gemini' => Lab::Gemini,
            default => Lab::OpenAI,
        };
    }

    private function extractionPrompt(): string
    {
        return <<<'PROMPT'
        Transcribi el contenido completo de este documento de forma LITERAL y FIEL.

        REGLAS:
        - Transcribi TODO el texto tal cual, sin omitir nada
        - Preserva tablas usando formato markdown (| col1 | col2 |)
        - Preserva listas, numeraciones, vinetas, notas al pie
        - NO resumas, NO interpretes, NO reorganices
        - NO agregues texto que no este en el documento
        - Si hay texto ilegible, indica [ilegible]
        - Usa encabezados markdown (## y ###) para las secciones del documento
        PROMPT;
    }
}
