<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuoteAlternative extends Model
{
    use HasFactory;
    use SoftDeletes;

    // Nota: Usamos SoftDeletes aquí para mantener la consistencia con la cabecera (Quote).
    // Aunque BD tenga cascade delete físico, el soft delete permite auditoría completa.

    protected $fillable = [
        'quote_id',
        'aseguradora',     // "Sancor"
        'descripcion',    // "C1 - Terceros Completos"
        'titulo',         // "C1"
        'normalized_grade', // 'A', 'B', 'C', 'D' (Vital para el Agente)
        'precio',
        'sum_asegurada',  // Suma asegurada numérica (insured_amount, vía adapter)
        'moneda',
        'payment_method_id', // Medio de pago del proveedor ("cbu" | "tarjeta" | "cupon")
        'marketing_title',   // Título comercial
        'sum_insured_text',  // Texto del Suma Asegurada
        'features_tags',     // JSON: Array simple de strings (["Granizo", "Ruedas"])
        'full_details',      // JSON: Objeto completo con descripciones ricas
    ];

    protected $casts = [
        'features_tags' => 'array',
        'full_details' => 'array',
        'precio' => 'decimal:2', // Asegura que siempre manejemos dinero con precisión
        'sum_asegurada' => 'decimal:2',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * Token opaco del proveedor para esta alternativa (quotation_result_id +
     * flag de inspección). Lo consume la emisión; el dominio no lo expone. ADR-001.
     *
     * @return HasOne<QuoteAlternativeProviderRef, $this>
     */
    public function providerRef(): HasOne
    {
        return $this->hasOne(QuoteAlternativeProviderRef::class);
    }

    /**
     * Scope para filtrar por grado normalizado (ej: solo terceros completos)
     */
    public function scopeGrade(Builder $query, string $grade): void
    {
        $query->where('normalized_grade', $grade);
    }
}
