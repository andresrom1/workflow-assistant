<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\CoverageDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $company_slug
 * @property string $company_name
 * @property string $document_type
 * @property string $original_filename
 * @property string $storage_path
 * @property string $storage_disk
 * @property string $mime_type
 * @property string|null $extracted_content
 * @property string $extraction_status
 * @property string $extraction_mode
 * @property string $extraction_provider
 * @property string|null $version
 * @property bool $is_active
 * @property string|null $deprecated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, CoverageChunk> $chunks
 * @property-read int|null $chunks_count
 */
class CoverageDocument extends Model
{
    /** @use HasFactory<CoverageDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'company_slug',
        'company_name',
        'document_type',
        'original_filename',
        'storage_path',
        'storage_disk',
        'mime_type',
        'extracted_content',
        'extraction_status',
        'extraction_mode',
        'extraction_provider',
        'version',
        'is_active',
        'deprecated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deprecated_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────────

    public function chunks(): HasMany
    {
        return $this->hasMany(CoverageChunk::class)->orderBy('chunk_index');
    }

    // ── Scopes ─────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCompany(Builder $query, string $companySlug): Builder
    {
        return $query->where('company_slug', $companySlug);
    }

    // ── Static Helpers ─────────────────────────────────────

    /**
     * Get all active documents for a company.
     *
     * @return Collection<int, self>
     */
    public static function activeForCompany(string $name): Collection
    {
        $slug = Str::slug($name);

        return static::where('company_slug', $slug)
            ->where('is_active', true)
            ->where('extraction_status', 'completed')
            ->whereNotNull('extracted_content')
            ->orderByRaw("CASE document_type WHEN 'insert' THEN 1 WHEN 'manual' THEN 2 WHEN 'asistencia' THEN 3 ELSE 4 END")
            ->get();
    }

    /**
     * Bust the cached chunks for this document's company.
     */
    public function bustCache(): void
    {
        Cache::forget("coverage_chunks:{$this->company_slug}");
    }

    /**
     * Deprecate this document.
     */
    public function deprecate(): void
    {
        $this->update([
            'is_active' => false,
            'deprecated_at' => now(),
        ]);

        $this->bustCache();
    }
}
