<?php

namespace App\Models;

use App\Enums\PolicyDocumentKind;
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
        'contrato_anterior_id',
        'periodo_corto',
        'no_renovar_at',
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
        'periodo_corto' => 'boolean',
        'no_renovar_at' => 'datetime',
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

    /**
     * Póliza que esta renueva/sucede (back-ref). Clearing por sucesora: una vieja se
     * cae de la cola cuando existe otra que la referencia (agnóstico del origen —
     * renovación o cambio de compañía).
     *
     * @return BelongsTo<Poliza, $this>
     */
    public function contratoAnterior(): BelongsTo
    {
        return $this->belongsTo(self::class, 'contrato_anterior_id');
    }

    /**
     * Pólizas que apuntan a esta como su anterior (sus sucesoras).
     *
     * @return HasMany<Poliza, $this>
     */
    public function sucesoras(): HasMany
    {
        return $this->hasMany(self::class, 'contrato_anterior_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeVigente(Builder $query): void
    {
        $query->where('estado', PolizaEstado::Vigente);
    }

    /**
     * Renovabilidad estructural (sin ventana temporal): gatea el botón "Renovar".
     * Permite renovar una vencida sin sucesora (caso escalado) y bloquea la doble
     * renovación (ya tiene sucesora).
     */
    public function esRenovable(): bool
    {
        return ! $this->periodo_corto
            && in_array($this->estado, [PolizaEstado::Vigente, PolizaEstado::Vencida], true)
            && $this->no_renovar_at === null
            && ! $this->sucesoras()->exists();
    }

    /**
     * Cola de renovaciones (estructural + ventana de 45 días): vigentes que vencen
     * pronto y vencidas sin sucesora (escaladas). Excluye período corto, descartadas,
     * ya sucedidas y sin `vigencia`.
     *
     * @param  Builder<self>  $q
     * @return Builder<self>
     */
    public function scopeARenovar(Builder $q): Builder
    {
        return $q->where('periodo_corto', false)
            ->whereIn('estado', [PolizaEstado::Vigente, PolizaEstado::Vencida])
            ->whereNotNull('vigencia')
            ->where('vigencia', '<=', now()->addDays(45))
            ->whereDoesntHave('sucesoras')
            ->whereNull('no_renovar_at');
    }

    /**
     * Pólizas activas (vigentes + emitidas) a las que les falta ≥1 documento esperado.
     * Extrae el cómputo del `whereRaw` que comparte la cola fusionada y el panel de
     * pendientes. El checklist vive en {@see PolicyDocumentKind::expectedForActivePolicy()}.
     *
     * @param  Builder<self>  $q
     * @return Builder<self>
     */
    public function scopeDocumentacionIncompleta(Builder $q): Builder
    {
        $expectedValues = array_map(
            fn (PolicyDocumentKind $k): string => $k->value,
            PolicyDocumentKind::expectedForActivePolicy(),
        );
        $placeholders = implode(',', array_fill(0, count($expectedValues), '?'));

        return $q->whereIn('estado', [PolizaEstado::Vigente, PolizaEstado::Emitida])
            ->whereRaw(
                "(select count(distinct kind) from policy_documents
                  where policy_documents.poliza_id = polizas.id and kind in ({$placeholders})) < ?",
                [...$expectedValues, count($expectedValues)],
            );
    }
}
