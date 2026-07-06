<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lote de facturación mensual: agrupa las Facturas C de una corrida y sus datos comunes.
 *
 * `estado` es un string simple (`processing` mientras el job factura, `completed` al cerrar);
 * `summary` guarda los conteos ({autorizadas, rechazadas, total}). El front hace polling hasta
 * que `finished_at` deja de ser null.
 *
 * @property int $id
 * @property string $codigo
 * @property string $concepto
 * @property int $punto_venta
 * @property Carbon $fecha_comprobante
 * @property Carbon $fecha_servicio_desde
 * @property Carbon $fecha_servicio_hasta
 * @property Carbon $fecha_vto_pago
 * @property string $estado
 * @property array<string, int>|null $summary
 * @property int|null $user_id
 * @property Carbon|null $finished_at
 */
class InvoiceBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'codigo',
        'concepto',
        'punto_venta',
        'fecha_comprobante',
        'fecha_servicio_desde',
        'fecha_servicio_hasta',
        'fecha_vto_pago',
        'estado',
        'summary',
        'user_id',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'punto_venta' => 'integer',
            'fecha_comprobante' => 'date',
            'fecha_servicio_desde' => 'date',
            'fecha_servicio_hasta' => 'date',
            'fecha_vto_pago' => 'date',
            'summary' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'batch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
