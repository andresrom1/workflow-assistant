<?php

namespace App\Models;

use App\Contracts\EmissionProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referencia opaca del proveedor por póliza, acotada a la captura DIFERIDA de
 * documentos (los que no estuvieron listos dentro de la ventana de `emit()`).
 *
 * Aísla del dominio (`polizas`) el token opaco que el puerto de emisión necesita
 * para re-pedir esos documentos más tarde (su valor es el `presale_id` de Visred,
 * pero el dominio lo trata como string opaco: lo persiste y se lo devuelve al
 * adapter vía {@see EmissionProvider::capturePendingDocuments()}).
 * Misma convención que {@see QuoteAlternativeProviderRef}. Ver docs/v2/10 §3.
 *
 * Efímera: se borra cuando no quedan `pending_document_kinds` (o cuando el job de
 * reintento agota su budget), porque el `presale_id` caduca.
 *
 * @property array<int, string> $pending_document_kinds
 */
class PolizaProviderRef extends Model
{
    protected $fillable = [
        'poliza_id',
        'document_token',
        'product_id',
        'pending_document_kinds',
        'last_attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'pending_document_kinds' => 'array',
            'last_attempted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Poliza, $this> */
    public function poliza(): BelongsTo
    {
        return $this->belongsTo(Poliza::class);
    }
}
