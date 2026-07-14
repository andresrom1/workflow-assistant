<?php

namespace App\Console\Commands;

use App\Enums\IngestaStatus;
use App\Jobs\ExtractIngestedDocument;
use App\Models\IngestedDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Herramienta de ops para la extracción v2 (ver docs/v3/04-ingesta-local-documentos.md):
 * re-despacha el job {@see ExtractIngestedDocument} para un documento puntual (p. ej. tras
 * mejorar el prompt del extractor) o para los que quedaron colgados en `en_extraccion`
 * (worker caído, job perdido).
 */
class ReextraerIngesta extends Command
{
    protected $signature = 'ingesta:reextraer
        {id? : ID del ingested_document a re-extraer}
        {--stuck : Re-despacha los en_extraccion con más de 30 minutos sin resolver}';

    protected $description = 'Re-despacha la extracción LLM de un documento ingestado (por id, o los colgados con --stuck)';

    public function handle(): int
    {
        $id = $this->argument('id');
        $stuck = (bool) $this->option('stuck');

        if ($id === null && ! $stuck) {
            $this->error('Especificá un id o --stuck.');

            return self::FAILURE;
        }

        if ($stuck) {
            return $this->reextraerColgados();
        }

        return $this->reextraerUno((int) $id);
    }

    private function reextraerUno(int $id): int
    {
        $doc = IngestedDocument::find($id);

        if ($doc === null) {
            $this->error("No existe ingested_document #{$id}.");

            return self::FAILURE;
        }

        if (! in_array($doc->status, [IngestaStatus::EnExtraccion, IngestaStatus::Pendiente], true)) {
            $this->error("El documento #{$id} está en estado '{$doc->status->value}' — solo se re-extraen los en 'en_extraccion' o 'pendiente'.");

            return self::FAILURE;
        }

        if (trim((string) data_get($doc->payload, 'texto')) === '') {
            $this->error("El documento #{$id} no tiene texto en su payload — no hay nada que re-extraer.");

            return self::FAILURE;
        }

        $doc->update(['status' => IngestaStatus::EnExtraccion]);
        ExtractIngestedDocument::dispatch($doc);

        $this->info("Re-extracción despachada para #{$id}.");

        return self::SUCCESS;
    }

    private function reextraerColgados(): int
    {
        $cutoff = Carbon::now()->subMinutes(30);

        $colgados = IngestedDocument::query()
            ->where('status', IngestaStatus::EnExtraccion)
            ->where('updated_at', '<', $cutoff)
            ->get();

        if ($colgados->isEmpty()) {
            $this->info('No hay documentos colgados en en_extraccion.');

            return self::SUCCESS;
        }

        foreach ($colgados as $doc) {
            ExtractIngestedDocument::dispatch($doc);
        }

        $this->info("Re-despachados {$colgados->count()} documento(s) colgado(s).");

        return self::SUCCESS;
    }
}
