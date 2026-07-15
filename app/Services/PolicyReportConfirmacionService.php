<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Enums\IngestaStatus;
use App\Enums\PolizaEstado;
use App\Enums\ReporteOrigen;
use App\Enums\ReporteRowAccion;
use App\Models\PolicyReportBatch;
use App\Models\PolicyReportRow;
use App\Models\Poliza;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Materializa un lote de reporte de cartera confirmado por el admin: por cada fila
 * `create`/`update_estado` resuelve la cadena `Customer→Risk→Poliza` (reusando
 * {@see PolicyChainResolver}, con dedup) y reconcilia estado/vigencia. Las `exception`/`noop`
 * se saltan. El reporte es autoridad del estado; la provenance va en `metadata.origen_reporte`.
 *
 * Re-deriva el match por `company+numero` al confirmar (idempotente): si la póliza ya existe
 * actualiza, si no la crea — no confía ciegamente en la acción del dry-run.
 */
class PolicyReportConfirmacionService
{
    public function __construct(
        private readonly PolicyChainResolver $chain,
    ) {}

    public function confirm(PolicyReportBatch $batch): PolicyReportBatch
    {
        if ($batch->status !== IngestaStatus::Pendiente) {
            throw ValidationException::withMessages(['status' => 'Este lote ya fue resuelto.']);
        }

        return DB::transaction(function () use ($batch): PolicyReportBatch {
            $rows = $batch->rows()
                ->whereIn('accion', [ReporteRowAccion::Create->value, ReporteRowAccion::UpdateEstado->value])
                ->get();

            foreach ($rows as $row) {
                $this->materialize($row, $batch->origen);
            }

            $batch->update([
                'status' => IngestaStatus::Confirmado,
                'confirmed_at' => now(),
            ]);

            return $batch->refresh();
        });
    }

    public function discard(PolicyReportBatch $batch): PolicyReportBatch
    {
        if ($batch->status !== IngestaStatus::Pendiente) {
            throw ValidationException::withMessages(['status' => 'Este lote ya fue resuelto.']);
        }

        $batch->update(['status' => IngestaStatus::Descartado]);

        return $batch->refresh();
    }

    private function materialize(PolicyReportRow $row, ReporteOrigen $origen): void
    {
        $estado = $row->estado_mapeado;
        if ($estado === null) {
            return; // defensivo: el dry-run sólo deja create/update con estado válido
        }

        $poliza = Poliza::where('company', $row->company)->where('numero', $row->numero)->first();

        if ($poliza instanceof Poliza) {
            $this->applyEstado($poliza, $estado, $row->vigencia, $origen);

            return;
        }

        if ($row->documento === null || $row->patente === null) {
            return; // defensivo: sin clave de cliente/risk no se crea
        }

        $customer = $this->chain->resolveCustomer($row->documento, ['razon_social' => $row->asegurado]);
        $risk = $this->chain->resolveRisk($customer, AssetType::Vehicle, ['patente' => $row->patente]);

        $risk->polizas()->create([
            'estado' => $estado,
            'numero' => $row->numero,
            'company' => $row->company,
            'vigencia' => $row->vigencia,
            'last_synced_at' => now(),
            'metadata' => ['origen_reporte' => $origen->value],
        ]);
    }

    private function applyEstado(Poliza $poliza, PolizaEstado $estado, ?Carbon $vigencia, ReporteOrigen $origen): void
    {
        $data = [
            'estado' => $estado,
            'last_synced_at' => now(),
            'metadata' => array_merge($poliza->metadata ?? [], ['origen_reporte' => $origen->value]),
        ];

        // No pisar la vigencia con null: sólo se actualiza si el reporte la trae.
        if ($vigencia !== null) {
            $data['vigencia'] = $vigencia;
        }

        $poliza->update($data);
    }
}
