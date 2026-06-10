<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referencia opaca del proveedor por alternativa de cotización.
 *
 * Aísla del dominio (`quote_alternatives`, ADR-001) el `quotation_result_id`
 * que la emisión necesita para emitir la cobertura elegida + el flag de
 * inspección pre-emisión. El dominio no conoce a Visred. Ver docs/v2/10 §3.
 */
class QuoteAlternativeProviderRef extends Model
{
    protected $fillable = [
        'quote_alternative_id',
        'external_quote_id',
        'company_id',
        'discount_id',
        'requires_inspection_before_emission',
    ];

    protected function casts(): array
    {
        return [
            'requires_inspection_before_emission' => 'boolean',
        ];
    }

    public function quoteAlternative(): BelongsTo
    {
        return $this->belongsTo(QuoteAlternative::class);
    }
}
