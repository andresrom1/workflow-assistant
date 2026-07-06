<?php

namespace App\Models;

use App\Enums\PolizaEstado;
use App\Enums\ReporteRowAccion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Fila estacionada de un reporte de cartera dentro de un {@see PolicyReportBatch}.
 *
 * Read-only en la revisión (se confirma/descarta el lote entero). Columnas denormalizadas
 * para listar el diff; `payload` es la fila cruda normalizada por el parser. `accion` y
 * `matched_poliza_id` los calcula el dry-run del import.
 *
 * @property int $id
 * @property int $batch_id
 * @property string|null $asegurado
 * @property string|null $documento
 * @property string|null $numero
 * @property string|null $company
 * @property string|null $producto
 * @property string|null $patente
 * @property string|null $estado_origen
 * @property PolizaEstado|null $estado_mapeado
 * @property Carbon|null $vigencia
 * @property ReporteRowAccion $accion
 * @property int|null $matched_poliza_id
 * @property string|null $nota
 * @property array<string, mixed> $payload
 */
class PolicyReportRow extends Model
{
    protected $fillable = [
        'batch_id',
        'asegurado',
        'documento',
        'numero',
        'company',
        'producto',
        'patente',
        'estado_origen',
        'estado_mapeado',
        'vigencia',
        'accion',
        'matched_poliza_id',
        'nota',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'estado_mapeado' => PolizaEstado::class,
            'vigencia' => 'date',
            'accion' => ReporteRowAccion::class,
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<PolicyReportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PolicyReportBatch::class, 'batch_id');
    }

    /** @return BelongsTo<Poliza, $this> */
    public function matchedPoliza(): BelongsTo
    {
        return $this->belongsTo(Poliza::class, 'matched_poliza_id');
    }
}
