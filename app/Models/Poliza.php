<?php

namespace App\Models;

use App\Enums\PolizaEstado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Póliza emitida sobre un Risk.
 *
 * Vehículo↔póliza es 1:N temporal. Solo una `vigente` a la vez por Risk
 * (constraint en código, no en DB).
 *
 * @property PolizaEstado $estado
 * @property array<string, mixed> $metadata
 * @property-read int|null $documents_count
 * @property-read int|null $visible_documents_count
 */
class Poliza extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'polizas';

    protected $fillable = [
        'risk_id',
        'quote_id',
        'estado',
        'numero',
        'contrato_anterior_ref',
        'company',
        'company_id',
        'product_id',
        'coverage',
        'coverage_detail',
        'sum_asegurada',
        'cuota',
        'cuota_due',
        'vigencia',
        'emitida_en',
        'last_synced_at',
        'metadata',
    ];

    protected $casts = [
        'estado' => PolizaEstado::class,
        'sum_asegurada' => 'decimal:2',
        'cuota' => 'decimal:2',
        'cuota_due' => 'date',
        'vigencia' => 'date',
        'emitida_en' => 'date',
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<Risk, $this> */
    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** @return HasMany<PolicyDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(PolicyDocument::class);
    }

    /** @return HasOne<PolicyDocument, $this> */
    public function latestDocument(): HasOne
    {
        return $this->hasOne(PolicyDocument::class)->latestOfMany('captured_at');
    }

    /**
     * Referencia opaca del proveedor para la captura diferida de documentos (solo
     * existe mientras queden PDFs pendientes de descargar). Ver {@see PolizaProviderRef}.
     *
     * @return HasOne<PolizaProviderRef, $this>
     */
    public function providerRef(): HasOne
    {
        return $this->hasOne(PolizaProviderRef::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeVigente(Builder $query): void
    {
        $query->where('estado', PolizaEstado::Vigente);
    }
}
