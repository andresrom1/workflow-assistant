<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referencia opaca al catálogo de un proveedor por snapshot de riesgo.
 *
 * Genérica (columna `provider`): aísla el token del catálogo (p.ej. el
 * `version_id` de Visred) del dominio. El nombre del proveedor vive como dato,
 * nunca en el schema. Ver docs/v2/10 §8.
 */
class RiskProviderRef extends Model
{
    protected $fillable = [
        'risk_snapshot_id',
        'provider',
        'external_vehicle_ref',
    ];

    public function riskSnapshot(): BelongsTo
    {
        return $this->belongsTo(RiskSnapshot::class);
    }
}
