<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuoteAlternative extends Model
{
    use SoftDeletes;

    // Nota: Usamos SoftDeletes aquí para mantener la consistencia con la cabecera (Quote).
    // Aunque BD tenga cascade delete físico, el soft delete permite auditoría completa.

    protected $fillable = [
        'quote_id',
        'external_code',    // ID para contratar (SKU)
        'external_quote_id',// ID Externo de la opción específica
        'aseguradora',     // "Sancor"
        'descripcion',    // "C1 - Terceros Completos"
        'titulo',         // "C1"
        'normalized_grade', // 'A', 'B', 'C', 'D' (Vital para el Agente)
        'precio',
        'moneda',
        'marketing_title',   // Título comercial
        'sum_insured_text',  // Texto del Suma Asegurada
        'features_tags',     // JSON: Array simple de strings (["Granizo", "Ruedas"])
        'full_details',      // JSON: Objeto completo con descripciones ricas
    ];

    protected $casts = [
        'features_tags' => 'array',
        'full_details' => 'array',
        'precio' => 'decimal:2', // Asegura que siempre manejemos dinero con precisión
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
    /**
     * Scope para filtrar por grado normalizado (ej: solo terceros completos)
     */
    public function scopeGrade(Builder $query, string $grade): void
    {
        $query->where('normalized_grade', $grade);
    }
}