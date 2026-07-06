<?php

namespace App\Services\Reports;

use App\Enums\PolizaEstado;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Parser del "Listado de Pólizas" exportado del Portal Visred (xlsx, 18 columnas).
 *
 * Mapea por **nombre de header normalizado** (robusto a reordenamiento). La columna "CUIT"
 * en realidad trae el DNI/documento. Descarta ID/Productor/Código/Premio/Costo/Estado Póliza
 * (no aportan a la cartera, y Premio/Costo no son la cuota). El vocabulario de estados es
 * propio de este origen, por eso el mapeo vive acá.
 */
class PortalVisredReportParser implements PolicyReportParser
{
    /**
     * Estados del reporte → PolizaEstado. `No-Vigente` es el paraguas (no se distingue
     * `Vencida`, que la determina la lógica de vencimientos, no este import).
     *
     * @var array<string, PolizaEstado>
     */
    private const ESTADO_MAP = [
        'vigente' => PolizaEstado::Vigente,
        'anulada' => PolizaEstado::Anulada,
        'programada' => PolizaEstado::Programada,
        'en proceso' => PolizaEstado::EnProceso,
        'no vigente' => PolizaEstado::NoVigente,
    ];

    public function parse(UploadedFile $file): array
    {
        $reader = new Reader;
        $reader->open($file->getPathname());

        try {
            $rows = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                $headerIndex = null;

                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();

                    // Primera fila no vacía = headers.
                    if ($headerIndex === null) {
                        $headerIndex = $this->headerIndex($cells);

                        continue;
                    }

                    $parsed = $this->parseRow($cells, $headerIndex);
                    if ($parsed !== null) {
                        $rows[] = $parsed;
                    }
                }

                break; // sólo la primera hoja
            }

            return $rows;
        } finally {
            $reader->close();
        }
    }

    /**
     * Mapa header normalizado → índice de columna.
     *
     * @param  list<mixed>  $cells
     * @return array<string, int>
     */
    private function headerIndex(array $cells): array
    {
        $index = [];

        foreach ($cells as $i => $value) {
            $key = $this->normalize((string) $value);
            if ($key !== '') {
                $index[$key] = $i;
            }
        }

        return $index;
    }

    /**
     * @param  list<mixed>  $cells
     * @param  array<string, int>  $headerIndex
     * @return array{
     *     asegurado: string|null, documento: string|null, numero: string|null,
     *     company: string|null, producto: string|null, ramo: string|null,
     *     patente: string|null, telefono: string|null, email: string|null,
     *     estado_origen: string|null, estado_mapeado: string|null, vigencia: string|null
     * }|null
     */
    private function parseRow(array $cells, array $headerIndex): ?array
    {
        $get = function (string $header) use ($cells, $headerIndex): ?string {
            $i = $headerIndex[$header] ?? null;
            if ($i === null || ! array_key_exists($i, $cells)) {
                return null;
            }
            $value = $cells[$i];
            $value = is_scalar($value) ? trim((string) $value) : '';

            return $value !== '' ? $value : null;
        };

        $estadoOrigen = $get('estado');
        $vigenciaCell = $headerIndex['fin vigencia'] ?? null;

        $row = [
            'asegurado' => $get('asegurado'),
            'documento' => $get('cuit'),
            'numero' => $get('nro de poliza'),
            'company' => $get('compania'),
            'producto' => $get('producto'),
            'ramo' => $get('ramo'),
            'patente' => $get('patente'),
            'telefono' => $get('telefono'),
            'email' => $get('email'),
            'estado_origen' => $estadoOrigen,
            'estado_mapeado' => $this->mapEstado($estadoOrigen)?->value,
            'vigencia' => $this->parseDate($vigenciaCell !== null ? ($cells[$vigenciaCell] ?? null) : null),
        ];

        // Fila vacía / fila de totales sin póliza ni asegurado → se ignora.
        if ($row['numero'] === null && $row['asegurado'] === null && $row['documento'] === null) {
            return null;
        }

        return $row;
    }

    private function mapEstado(?string $estado): ?PolizaEstado
    {
        if ($estado === null) {
            return null;
        }

        return self::ESTADO_MAP[$this->normalize($estado)] ?? null;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * Normaliza un texto para comparar headers/estados: minúsculas, sin acentos, sin signos
     * de separación (- _), espacios colapsados.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ñ' => 'n', 'ü' => 'u',
        ]);
        $value = str_replace(['-', '_'], ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
