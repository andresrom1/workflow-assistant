<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

/**
 * @property int $id
 * @property int $coverage_document_id
 * @property int $chunk_index
 * @property string $content
 * @property Vector|null $embedding
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CoverageDocument $coverageDocument
 */
class CoverageChunk extends Model
{
    use HasNeighbors;

    protected $fillable = [
        'coverage_document_id',
        'chunk_index',
        'content',
        'embedding',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => Vector::class,
            'metadata' => 'array',
        ];
    }

    public function coverageDocument(): BelongsTo
    {
        return $this->belongsTo(CoverageDocument::class);
    }
}
