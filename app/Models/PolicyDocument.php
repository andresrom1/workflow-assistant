<?php

namespace App\Models;

use App\Enums\PolicyDocumentKind;
use App\Enums\PolicyDocumentSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Documento oficial de una póliza persistido en R2.
 *
 * `source=visred_emission` se captura al emitir (snapshot, ver doc 10 §5);
 * `source=admin_upload` es carga manual post-emisión. Los documentos se acumulan al
 * contrato: NO se reemplazan, conviven varios del mismo `kind`. `presale_id` NO vive
 * acá: es un dato de Visred que no sale del adapter.
 *
 * @property int $poliza_id
 * @property PolicyDocumentKind $kind
 * @property string $storage_path
 * @property string|null $storage_url
 * @property string|null $original_filename
 * @property string|null $label
 * @property PolicyDocumentSource $source
 * @property bool $visible_to_client
 * @property Carbon|null $captured_at
 */
class PolicyDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'poliza_id',
        'kind',
        'storage_path',
        'storage_url',
        'original_filename',
        'label',
        'source',
        'visible_to_client',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PolicyDocumentKind::class,
            'source' => PolicyDocumentSource::class,
            'visible_to_client' => 'boolean',
            'captured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Poliza, $this> */
    public function poliza(): BelongsTo
    {
        return $this->belongsTo(Poliza::class);
    }
}
