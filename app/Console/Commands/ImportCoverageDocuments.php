<?php

namespace App\Console\Commands;

use App\Models\CoverageDocument;
use App\Services\ChunkAndEmbedService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mete el texto curado de vuelta en `coverage_documents.extracted_content` y re-indexa.
 *
 * Hace lo mismo que guardar desde el admin (`CoverageDocumentController::update`), pero desde un
 * archivo, para poder curar en un editor y para poder cargar produccion sin pasar 90.000
 * caracteres por un textarea.
 *
 * Empareja por `company_slug` + `document_type` del encabezado. **Si no existe, lo crea**: es el
 * caso de produccion, donde todavia no hay ningun documento cargado y el PDF original solo esta
 * en la maquina de quien curo el texto. El PDF no hace falta en runtime — el agente solo lee
 * `extracted_content`.
 */
#[Signature('coverage:import {path : Un archivo .md o una carpeta con varios} {--dry-run : Muestra que haria, sin escribir ni re-indexar}')]
#[Description('Importa texto curado de coberturas desde archivos .md, lo guarda y regenera los chunks.')]
class ImportCoverageDocuments extends Command
{
    public function handle(ChunkAndEmbedService $chunker): int
    {
        $path = (string) $this->argument('path');

        $archivos = match (true) {
            is_dir($path) => glob(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.md') ?: [],
            is_file($path) => [$path],
            default => [],
        };

        if ($archivos === []) {
            $this->error("No encontre ningun .md en {$path}");

            return self::FAILURE;
        }

        $seco = (bool) $this->option('dry-run');
        $filas = [];
        $sinIndexar = [];

        foreach ($archivos as $archivo) {
            [$meta, $texto] = $this->separarEncabezado((string) file_get_contents($archivo));

            $nombreCompania = $meta['company_name'] ?? null;
            $tipo = $meta['document_type'] ?? null;

            if ($nombreCompania === null || $tipo === null) {
                $this->error(basename($archivo).': falta company_name o document_type en el encabezado.');

                return self::FAILURE;
            }

            if (trim($texto) === '') {
                $this->error(basename($archivo).': el cuerpo esta vacio.');

                return self::FAILURE;
            }

            $slug = Str::slug($nombreCompania);
            $documento = CoverageDocument::where('company_slug', $slug)
                ->where('document_type', $tipo)
                ->where('is_active', true)
                ->first();

            $filas[] = [
                basename($archivo),
                $documento instanceof CoverageDocument ? 'actualiza' : 'crea',
                number_format(mb_strlen($texto)),
                $seco ? '—' : '',
            ];

            if ($seco) {
                continue;
            }

            $documento ??= new CoverageDocument([
                'company_slug' => $slug,
                'company_name' => $nombreCompania,
                'document_type' => $tipo,
                'original_filename' => $meta['original_filename'] ?? basename($archivo),
                'storage_path' => "coverage-documents/{$slug}/".($meta['original_filename'] ?? basename($archivo)),
                'storage_disk' => 'local',
                'mime_type' => 'application/pdf',
                'is_active' => true,
            ]);

            $documento->fill([
                'version' => ($meta['version'] ?? '') ?: $documento->version,
                'extracted_content' => $texto,
                'extraction_status' => 'completed',
                'extraction_mode' => 'manual',
            ])->save();

            // El indexado es OPCIONAL y no puede voltear la importacion: el agente lee
            // `extracted_content`, que ya quedo guardado arriba. Los chunks solo alimentan la
            // busqueda por similitud, que hoy no se usa —ningun manual supera el presupuesto de
            // caracteres— y que ademas depende de un proveedor externo: el free tier de Gemini
            // corta por cuota a mitad de una carga de diez documentos. Se avisa y se sigue;
            // `coverage:re-embed` los completa despues.
            try {
                $filas[count($filas) - 1][3] = (string) $chunker->execute($documento);
            } catch (Throwable $e) {
                $filas[count($filas) - 1][3] = 'falló';
                $sinIndexar[] = $documento->id;
                $this->warn('  '.basename($archivo).': el texto se guardó, el indexado falló — '.$e->getMessage());
            }
        }

        $this->newLine();
        $this->table(['archivo', 'accion', 'chars', 'chunks'], $filas);

        if ($seco) {
            $this->warn('Modo seco: no se escribio nada. Saca --dry-run para aplicar.');

            return self::SUCCESS;
        }

        $this->info('Listo. El agente ya lee el texto nuevo.');

        if ($sinIndexar !== []) {
            $this->newLine();
            $this->warn(count($sinIndexar).' documento(s) quedaron sin indexar. Para completarlos:');

            foreach ($sinIndexar as $id) {
                $this->line("  php artisan coverage:re-embed --id={$id}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array{array<string, string>, string}
     */
    private function separarEncabezado(string $contenido): array
    {
        $contenido = preg_replace('/^\x{FEFF}/u', '', $contenido) ?? $contenido;

        if (! preg_match("/\A---\r?\n(.*?)\r?\n---\r?\n(.*)\z/s", $contenido, $partes)) {
            return [[], $contenido];
        }

        $meta = [];

        foreach (preg_split('/\r?\n/', $partes[1]) ?: [] as $linea) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', trim($linea), $par)) {
                $meta[$par[1]] = trim($par[2]);
            }
        }

        return [$meta, trim($partes[2])];
    }
}
