<?php

namespace App\Services;

use App\Enums\IngestaStatus;
use App\Enums\PolizaEstado;
use App\Enums\ReporteOrigen;
use App\Enums\ReporteRowAccion;
use App\Models\Customer;
use App\Models\PolicyReportBatch;
use App\Models\PolicyReportRow;
use App\Models\Poliza;
use App\Services\Reports\ReportParserFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Estaciona un reporte de cartera subido al panel: lo parsea (según su origen) y crea un
 * lote `pendiente` con cada fila evaluada en **dry-run** (sin escribir dominio). La
 * materialización es aparte, tras confirmación humana ({@see PolicyReportConfirmacionService}).
 *
 * Agnóstico de origen: el {@see ReportParserFactory} elige el parser. Idempotente por
 * `hash_sha256` del archivo — re-subir el mismo reporte devuelve el lote existente.
 */
class PolicyReportImportService
{
    public function __construct(
        private readonly ReportParserFactory $parsers,
    ) {}

    public function stage(ReporteOrigen $origen, UploadedFile $file): PolicyReportBatch
    {
        $hash = (string) hash_file('sha256', $file->getPathname());

        $existing = PolicyReportBatch::firstWhere('hash_sha256', $hash);
        if ($existing instanceof PolicyReportBatch) {
            return $existing;
        }

        $rows = $this->parsers->for($origen)->parse($file);

        return DB::transaction(function () use ($origen, $file, $hash, $rows): PolicyReportBatch {
            $batch = PolicyReportBatch::create([
                'origen' => $origen,
                'original_filename' => $file->getClientOriginalName(),
                'hash_sha256' => $hash,
                'status' => IngestaStatus::Pendiente,
                'uploaded_at' => now(),
            ]);

            $summary = [
                'create' => 0,
                'update_estado' => 0,
                'noop' => 0,
                'exception' => 0,
                'total' => 0,
                'nuevos_clientes' => 0,
            ];
            $nuevosClientes = [];

            foreach ($rows as $row) {
                [$accion, $matchedId, $nota] = $this->evaluate($row);

                PolicyReportRow::create([
                    'batch_id' => $batch->id,
                    'asegurado' => $row['asegurado'],
                    'documento' => $row['documento'],
                    'numero' => $row['numero'],
                    'company' => $row['company'],
                    'producto' => $row['producto'],
                    'patente' => $row['patente'],
                    'estado_origen' => $row['estado_origen'],
                    'estado_mapeado' => $row['estado_mapeado'],
                    'vigencia' => $row['vigencia'],
                    'accion' => $accion,
                    'matched_poliza_id' => $matchedId,
                    'nota' => $nota,
                    'payload' => $row,
                ]);

                $summary[$accion->value]++;
                $summary['total']++;

                if ($accion === ReporteRowAccion::Create
                    && $row['documento'] !== null
                    && $this->customerMissing($row['documento'])) {
                    $nuevosClientes[$row['documento']] = true;
                }
            }

            $summary['nuevos_clientes'] = count($nuevosClientes);
            $batch->update(['summary' => $summary]);

            return $batch->refresh();
        });
    }

    /**
     * Dry-run de una fila: qué haría sin escribir nada. El reporte es autoridad del estado;
     * la invariante "una vigente por Risk" se respeta marcando excepción (import no destructivo).
     *
     * @param  array<string, string|null>  $row
     * @return array{0: ReporteRowAccion, 1: int|null, 2: string|null}
     */
    private function evaluate(array $row): array
    {
        $documento = $row['documento'];
        $numero = $row['numero'];
        $company = $row['company'];
        $estado = $row['estado_mapeado'] !== null ? PolizaEstado::tryFrom($row['estado_mapeado']) : null;

        if ($documento === null || $numero === null || $company === null) {
            return [ReporteRowAccion::Exception, null, 'Falta documento, número o compañía.'];
        }

        $poliza = Poliza::where('company', $company)->where('numero', $numero)->first();

        if ($poliza instanceof Poliza) {
            if ($estado === null) {
                return [ReporteRowAccion::Noop, $poliza->id, null];
            }

            $sinCambios = $poliza->estado === $estado
                && $poliza->vigencia?->toDateString() === $row['vigencia'];

            if ($sinCambios) {
                return [ReporteRowAccion::Noop, $poliza->id, null];
            }

            if ($estado === PolizaEstado::Vigente && $this->otherVigenteExists($poliza->risk_id, $poliza->id)) {
                return [ReporteRowAccion::Exception, $poliza->id, 'El vehículo ya tiene otra póliza vigente.'];
            }

            return [ReporteRowAccion::UpdateEstado, $poliza->id, null];
        }

        // No existe → sería un alta nueva.
        if ($row['patente'] === null) {
            return [ReporteRowAccion::Exception, null, 'Sin patente: ramo no-vehicular aún no soportado.'];
        }

        if ($estado === null) {
            return [ReporteRowAccion::Exception, null, 'Estado del reporte no reconocido: '.($row['estado_origen'] ?? '—')];
        }

        if ($estado === PolizaEstado::Vigente && $this->vigenteExistsForPatente($documento, $row['patente'])) {
            return [ReporteRowAccion::Exception, null, 'El vehículo ya tiene una póliza vigente.'];
        }

        return [ReporteRowAccion::Create, null, null];
    }

    private function customerMissing(string $dni): bool
    {
        return ! Customer::where('dni', $dni)->exists();
    }

    private function otherVigenteExists(int $riskId, int $exceptPolizaId): bool
    {
        return Poliza::where('risk_id', $riskId)
            ->where('estado', PolizaEstado::Vigente)
            ->where('id', '!=', $exceptPolizaId)
            ->exists();
    }

    private function vigenteExistsForPatente(string $dni, string $patente): bool
    {
        $customer = Customer::where('dni', $dni)->first();
        if ($customer === null) {
            return false;
        }

        $risk = $customer->risks()->where('metadata->patente', $patente)->first();
        if ($risk === null) {
            return false;
        }

        return $risk->polizas()->where('estado', PolizaEstado::Vigente)->exists();
    }
}
