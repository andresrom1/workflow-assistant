<?php

namespace App\Models;

use App\Enums\IngestaStatus;
use App\Enums\PolicyDocumentKind;
use Database\Factories\IngestedDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Documento de póliza estacionado por el ingestor local, a la espera de confirmación
 * humana antes de materializar la cadena Customer→Risk→Poliza→PolicyDocument (doc v3/04 §5).
 *
 * El parseo vive en el cliente; `payload` es el contrato §2 crudo (fuente de verdad). Las
 * columnas denormalizadas (numero_poliza/patente/...) son para listar y agrupar.
 *
 * @property int $id
 * @property string $hash_sha256
 * @property PolicyDocumentKind $kind
 * @property string|null $compania
 * @property string|null $numero_poliza
 * @property string|null $documento_numero
 * @property string|null $patente
 * @property IngestaStatus $status
 * @property string|null $original_filename
 * @property string $storage_path
 * @property string|null $storage_url
 * @property Carbon|null $detectado_en
 * @property array<string, mixed> $payload
 * @property list<string>|null $campos_no_extraidos
 * @property int|null $poliza_id
 * @property int|null $policy_document_id
 */
class IngestedDocument extends Model
{
    /** @use HasFactory<IngestedDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'hash_sha256',
        'kind',
        'compania',
        'numero_poliza',
        'documento_numero',
        'patente',
        'status',
        'original_filename',
        'storage_path',
        'storage_url',
        'detectado_en',
        'payload',
        'campos_no_extraidos',
        'poliza_id',
        'policy_document_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PolicyDocumentKind::class,
            'status' => IngestaStatus::class,
            'detectado_en' => 'datetime',
            'payload' => 'array',
            'campos_no_extraidos' => 'array',
        ];
    }

    /** @return BelongsTo<Poliza, $this> */
    public function poliza(): BelongsTo
    {
        return $this->belongsTo(Poliza::class);
    }

    /** @return BelongsTo<PolicyDocument, $this> */
    public function policyDocument(): BelongsTo
    {
        return $this->belongsTo(PolicyDocument::class);
    }
}
