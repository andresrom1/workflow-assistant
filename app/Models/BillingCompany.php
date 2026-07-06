<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Compañía a la que el productor factura comisiones (receptor de Facturas C).
 *
 * Padrón fiscal propio del módulo de facturación, desacoplado del catálogo de aseguradoras
 * de Visred (incluye compañías ajenas como Cooperación, LPS).
 *
 * @property int $id
 * @property string $razon_social
 * @property string $cuit
 * @property string $condicion_iva
 * @property string|null $domicilio
 * @property bool $activo
 */
class BillingCompany extends Model
{
    use HasFactory;

    protected $fillable = [
        'razon_social',
        'cuit',
        'condicion_iva',
        'domicilio',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
