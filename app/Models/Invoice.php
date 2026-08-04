<?php

namespace App\Models;

use App\Enums\InvoiceEstado;
use App\Jobs\EmitInvoice;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Comprobante fiscal (Factura C) emitido o intentado contra AFIP. Registro contable inmutable:
 * los datos del receptor se snapshotean al crearlo. `numero_comprobante`/`cae`/`cae_vencimiento`
 * los completa AFIP al autorizar; `observaciones` guarda el error si rechaza.
 *
 * `numero_reservado` es distinto: es el número que se le VA a pedir a AFIP, escrito ANTES de la
 * llamada. Si el proceso muere entre la autorización y su persistencia, esa reserva es el único
 * rastro de qué comprobante puede haber quedado emitido — {@see EmitInvoice} lo consulta
 * en vez de asumir hacia adelante. Se libera (vuelve a null) si AFIP rechaza, porque un rechazo no
 * consume número.
 *
 * Invariante: `numero_comprobante IS NOT NULL` ⟺ `estado === Authorized`.
 *
 * @property int $id
 * @property int $batch_id
 * @property int $billing_company_id
 * @property string $importe
 * @property int $pto_vta
 * @property int $tipo_comprobante
 * @property int|null $numero_comprobante
 * @property int|null $numero_reservado
 * @property string $codigo
 * @property string|null $cae
 * @property Carbon|null $cae_vencimiento
 * @property Carbon $fecha_comprobante
 * @property Carbon $fecha_servicio_desde
 * @property Carbon $fecha_servicio_hasta
 * @property Carbon $fecha_vto_pago
 * @property string $receptor_razon_social
 * @property string $receptor_cuit
 * @property string $receptor_condicion_iva
 * @property string|null $receptor_domicilio
 * @property InvoiceEstado $estado
 * @property string|null $observaciones
 */
class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'billing_company_id',
        'importe',
        'pto_vta',
        'tipo_comprobante',
        'numero_comprobante',
        'numero_reservado',
        'codigo',
        'cae',
        'cae_vencimiento',
        'fecha_comprobante',
        'fecha_servicio_desde',
        'fecha_servicio_hasta',
        'fecha_vto_pago',
        'receptor_razon_social',
        'receptor_cuit',
        'receptor_condicion_iva',
        'receptor_domicilio',
        'estado',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'importe' => 'decimal:2',
            'pto_vta' => 'integer',
            'tipo_comprobante' => 'integer',
            'numero_comprobante' => 'integer',
            'numero_reservado' => 'integer',
            'cae_vencimiento' => 'date',
            'fecha_comprobante' => 'date',
            'fecha_servicio_desde' => 'date',
            'fecha_servicio_hasta' => 'date',
            'fecha_vto_pago' => 'date',
            'estado' => InvoiceEstado::class,
        ];
    }

    /** @return BelongsTo<InvoiceBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(InvoiceBatch::class, 'batch_id');
    }

    /** @return BelongsTo<BillingCompany, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(BillingCompany::class, 'billing_company_id');
    }
}
