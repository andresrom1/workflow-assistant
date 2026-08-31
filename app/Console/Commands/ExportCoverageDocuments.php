<?php

namespace App\Console\Commands;

use App\Models\CoverageDocument;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Saca el texto de los manuales a archivos `.md` para curarlos en un editor.
 *
 * El textarea del admin es inviable para 90.000 caracteres, y el texto de estos manuales es la
 * fuente de verdad de lo que el agente le promete al cliente: se cura a mano, no con un LLM,
 * porque una transcripcion automatica puede comerse o inventar un numero en silencio.
 *
 * El archivo lleva encabezado YAML para que `coverage:import` sepa a que documento vuelve.
 */
#[Signature('coverage:export {--company= : Solo esta compania (slug, ej: san-cristobal)} {--dir= : Carpeta destino (default: storage/app/coverage-md)} {--all : Incluir los documentos deprecados}')]
#[Description('Exporta el texto de los documentos de cobertura a archivos .md editables, con sus metricas de calidad.')]
class ExportCoverageDocuments extends Command
{
    public function handle(): int
    {
        $dir = (string) ($this->option('dir') ?: storage_path('app/coverage-md'));

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("No se pudo crear la carpeta {$dir}");

            return self::FAILURE;
        }

        $query = CoverageDocument::query()->orderBy('company_slug')->orderBy('document_type');

        if (! $this->option('all')) {
            $query->where('is_active', true);
        }

        if ($company = $this->option('company')) {
            $query->where('company_slug', $company);
        }

        $documentos = $query->get();

        if ($documentos->isEmpty()) {
            $this->warn('No hay documentos que exportar.');

            return self::SUCCESS;
        }

        foreach ($documentos as $documento) {
            $nombre = "{$documento->company_slug}--{$documento->document_type}.md";

            file_put_contents(
                $dir.DIRECTORY_SEPARATOR.$nombre,
                $this->conEncabezado($documento, (string) $documento->extracted_content),
            );

            $this->line("  {$nombre}");
        }

        $this->newLine();
        $this->info("Exportados {$documentos->count()} documento(s) a {$dir}");
        $this->line('Cura los .md contra el PDF y volve con: php artisan coverage:import '.$dir);

        return self::SUCCESS;
    }

    private function conEncabezado(CoverageDocument $documento, string $texto): string
    {
        return "---\n"
            ."company_name: {$documento->company_name}\n"
            ."document_type: {$documento->document_type}\n"
            .'version: '.($documento->version ?? '')."\n"
            ."original_filename: {$documento->original_filename}\n"
            ."---\n\n"
            .$texto."\n";
    }
}
