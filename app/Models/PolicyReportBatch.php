<?php

namespace App\Models;

use App\Enums\IngestaStatus;
use App\Enums\ReporteOrigen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lote de import de un reporte de cartera (snapshot de pólizas) subido al panel, a la
 * espera de confirmación humana antes de materializar la cadena Customer→Risk→Poliza.
 *
 * Reusa {@see IngestaStatus} (pendiente/confirmado/descartado). `summary` guarda los
 * conteos del dry-run; las filas viven en {@see PolicyReportRow}.
 *
 * @property int $id
 * @property ReporteOrigen $origen
 * @property string|null $original_filename
 * @property string $hash_sha256
 * @property IngestaStatus $status
 * @property array<string, int>|null $summary
 * @property Carbon|null $uploaded_at
 * @property Carbon|null $confirmed_at
 */
class PolicyReportBatch extends Model
{
    protected $fillable = [
        'origen',
        'original_filename',
        'hash_sha256',
        'status',
        'summary',
        'uploaded_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'origen' => ReporteOrigen::class,
            'status' => IngestaStatus::class,
            'summary' => 'array',
            'uploaded_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return HasMany<PolicyReportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(PolicyReportRow::class, 'batch_id');
    }
}
