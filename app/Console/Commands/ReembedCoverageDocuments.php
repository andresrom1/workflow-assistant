<?php

namespace App\Console\Commands;

use App\Models\CoverageDocument;
use App\Services\ChunkAndEmbedService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('coverage:re-embed {--id= : Re-embed only this document id}')]
#[Description('Regenerate chunks and embeddings for all coverage documents with the currently configured embeddings provider.')]
class ReembedCoverageDocuments extends Command
{
    public function handle(ChunkAndEmbedService $service): int
    {
        $query = CoverageDocument::query();

        if ($id = $this->option('id')) {
            $query->whereKey($id);
        }

        $documents = $query->get();

        if ($documents->isEmpty()) {
            $this->warn('No coverage documents found.');

            return self::SUCCESS;
        }

        $this->info("Re-embedding {$documents->count()} document(s) with provider: ".config('ai.default_for_embeddings'));

        $totalChunks = 0;

        foreach ($documents as $document) {
            $this->line("-> [{$document->id}] {$document->company_name} / {$document->document_type}");

            try {
                $count = $service->execute($document);
                $totalChunks += $count;
                $this->info("   {$count} chunks OK");
            } catch (\Throwable $e) {
                $this->error("   failed: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Done. Total chunks generated: {$totalChunks}");

        return self::SUCCESS;
    }
}
