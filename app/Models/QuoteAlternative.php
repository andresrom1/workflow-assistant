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

    /**
     * ¿Se le puede ofrecer al cliente?
     *
     * Visred cotiza el mismo cover una vez por medio de pago, y el checkout solo procesa
     * tarjeta de crédito: ofrecer una variante que después no se puede pagar es venderle algo
     * inexistente. Filtrando por esto la cotización queda además con una sola fila por
     * producto — sin el filtro el mismo plan aparece hasta tres veces con precios distintos.
     *
     * Las alternativas anteriores al 2026-08-08 no tienen `payment_method_id` (la columna es
     * nueva) y se conservan: los links de cotización ya enviados tienen que seguir abriendo.
     */
    public function esOfrecible(): bool
    {
        if ($this->payment_method_id === null) {
            return true;
        }

        return in_array($this->payment_method_id, (array) config('quotes.medios_de_pago_ofrecibles', []), true);
    }

    /**
     * Franquicia en pesos, cuando el proveedor la expresa como porcentaje en el título.
     *
     * El payload de Visred NO trae un campo de franquicia: viaja adentro del nombre del
     * producto — `Todo Riesgo Franquicia 7,5% suma asegurada`, `Todo Riesgo 8% Suma Aseg,
     * Franquicia`, `T37 - Todo Riesgo Franq 7% Suma Aseg`. Con `sum_asegurada` alcanza para
     * resolverla sin ir al manual, y se calcula acá en vez de pedírselo al modelo: es
     * aritmética, no criterio.
     *
     * Devuelve null cuando el título no la expresa así — `Franquicia Fija`, `Franquicia
     * Variable` y los planes sin franquicia caen acá. En ese caso el dato sale del manual o
     * no sale: **null no significa "sin franquicia"**, significa "no derivable del título".
     *
     * @return array{porcentaje: float, monto: float, origen: string}|null
     */
    public function franquicia(): ?array
    {
        $titulo = (string) $this->titulo;
        $suma = (float) $this->sum_asegurada;

        if ($suma <= 0.0) {
            return null;
        }

        // Exige la palabra franquicia/franq en el título: sin ella, un porcentaje suelto
        // puede ser cualquier otra cosa (bonificación, tope de inundación).
        if (! preg_match('/franq/iu', $titulo)) {
            return null;
        }

        // Y exige que la base declarada sea la suma asegurada. Sin este filtro, dos familias
        // de títulos reales de producción darían un número equivocado dicho con seguridad:
        //   `Todo Riesgo Franquicia 10% suma 0KM`        → la base es el valor 0km, no la SA
        //   `D3 - Todo Riesgo Franq 10% - Min $ 400.000` → hay un piso que el porcentaje no
        //                                                   expresa, y puede ganarle
        // Los dos quedan afuera por no decir "suma aseg". Caen a null, o sea al manual.
        if (! preg_match('/suma\s*aseg/iu', $titulo)) {
            return null;
        }

        if (! preg_match('/(\d{1,2})(?:[.,](\d{1,2}))?\s*%/u', $titulo, $m)) {
            return null;
        }

        $porcentaje = (float) ($m[1].'.'.($m[2] ?? '0'));

        return [
            'porcentaje' => $porcentaje,
            'monto' => round($suma * $porcentaje / 100, 2),
            'origen' => $titulo,
        ];
    }
}
