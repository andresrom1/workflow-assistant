<?php

namespace App\Console\Commands;

use App\Enums\PolicyDocumentKind;
use App\Enums\PolicyDocumentSource;
use App\Jobs\CapturePendingPolicyDocuments;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('policy:recapture-documents {poliza_id : ID de la póliza} {document_token : Token opaco del proveedor (presale_id de Visred)} {--product-id=auto : product_id que le pasaremos al proveedor} {--kinds=poliza,certificado,circulation-card : Kinds de dominio a recapturar, separados por coma} {--delay=0 : Segundos de delay antes de encolar el job}')]
#[Description('Recrea la referencia de documentos pendientes y encola la captura diferida.')]
class RecapturePolicyDocuments extends Command
{
    public function handle(): int
    {
        $polizaId = (int) $this->argument('poliza_id');
        $documentToken = trim((string) $this->argument('document_token'));
        $productId = trim((string) $this->option('product-id'));
        $delay = (int) $this->option('delay');

        $poliza = Poliza::query()->find($polizaId);

        if ($poliza === null) {
            $this->error("No existe la póliza [{$polizaId}].");

            return self::FAILURE;
        }

        if ($documentToken === '') {
            $this->error('El document_token no puede estar vacío.');

            return self::FAILURE;
        }

        if ($productId === '') {
            $this->error('El --product-id no puede estar vacío.');

            return self::FAILURE;
        }

        $requestedKinds = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('kinds')))));

        if ($requestedKinds === []) {
            $this->error('La lista de --kinds está vacía.');

            return self::FAILURE;
        }

        $invalidKinds = [];
        foreach ($requestedKinds as $kind) {
            if (PolicyDocumentKind::tryFrom($kind) === null) {
                $invalidKinds[] = $kind;
            }
        }

        if ($invalidKinds !== []) {
            $this->error('Kinds inválidos: '.implode(', ', $invalidKinds));

            return self::FAILURE;
        }

        $existingKinds = PolicyDocument::query()
            ->where('poliza_id', $poliza->id)
            ->where('source', PolicyDocumentSource::VisredEmission)
            ->whereIn('kind', $requestedKinds)
            ->pluck('kind')
            ->map(static fn (PolicyDocumentKind $kind): string => $kind->value)
            ->all();

        $missingKinds = array_values(array_diff($requestedKinds, $existingKinds));

        if ($missingKinds === []) {
            $this->warn('Todos los documentos solicitados ya existen para esta póliza.');

            return self::SUCCESS;
        }

        $poliza->providerRef()->updateOrCreate(
            ['poliza_id' => $poliza->id],
            [
                'document_token' => $documentToken,
                'product_id' => $productId,
                'pending_document_kinds' => $missingKinds,
            ],
        );

        CapturePendingPolicyDocuments::dispatch($poliza->id)
            ->delay(now()->addSeconds($delay));

        $this->info("Referencia recreada para póliza [{$poliza->id}].");
        $this->info('Documentos pendientes: '.implode(', ', $missingKinds));
        $this->info('Job encolado en cola default'.($delay > 0 ? " con delay de {$delay}s" : '').'.');

        return self::SUCCESS;
    }
}
