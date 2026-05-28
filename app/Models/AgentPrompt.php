<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * @property-read User|null $owner
 * @property-read self|null $parentVersion
 */
class AgentPrompt extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'agent_key',
        'type',
        'content',
        'version',
        'is_active',
        'status',
        'owner_id',
        'parent_version_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Invariante: un solo draft por agent_key. Se valida en save() para cortar
        // race conditions a nivel aplicación (complementa el índice parcial si se agrega).
        static::saving(function (self $prompt): void {
            if ($prompt->status !== self::STATUS_DRAFT) {
                return;
            }

            $exists = static::query()
                ->where('agent_key', $prompt->agent_key)
                ->where('status', self::STATUS_DRAFT)
                ->when($prompt->exists, fn (Builder $q) => $q->where('id', '!=', $prompt->id))
                ->exists();

            if ($exists) {
                throw new DomainException(
                    "Ya existe un draft para el agente '{$prompt->agent_key}'. Tomá el control o descartalo antes de crear uno nuevo."
                );
            }
        });
    }

    // ─── Relaciones ──────────────────────────────────────────────────────────

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function parentVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_version_id');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeForAgent(Builder $query, string $key): Builder
    {
        return $query->where('agent_key', $key);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ARCHIVED);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('owner_id', $user->id);
    }

    public function scopeShared(Builder $query): Builder
    {
        return $query->where('type', 'shared');
    }

    public function scopeAgents(Builder $query): Builder
    {
        return $query->where('type', 'agent');
    }

    // ─── Helpers estáticos ───────────────────────────────────────────────────

    public static function activeFor(string $key): ?self
    {
        $cacheKey = self::cacheKey($key);

        $cached = Cache::get($cacheKey);

        if ($cached !== null && ! $cached instanceof self) {
            Cache::forget($cacheKey);
            $cached = null;
        }

        if ($cached === null) {
            $cached = static::forAgent($key)->active()->first();
            if ($cached !== null) {
                Cache::forever($cacheKey, $cached);
            }
        }

        return $cached;
    }

    public static function draftFor(string $key): ?self
    {
        return static::forAgent($key)->draft()->first();
    }

    public static function nextVersionFor(string $key): int
    {
        return (static::forAgent($key)->max('version') ?? 0) + 1;
    }

    /**
     * Compose final prompt by concatenating shared blocks + specific agent prompt.
     *
     * @param  array<int, string>  $sharedKeys
     */
    public static function compose(string $agentKey, array $sharedKeys = []): string
    {
        return collect([...$sharedKeys, $agentKey])
            ->map(fn (string $key) => static::activeFor($key)?->content)
            ->filter()
            ->implode("\n\n");
    }

    // ─── Draft flow ──────────────────────────────────────────────────────────

    /**
     * Activa esta versión como la vigente y desactiva el resto del mismo agent_key.
     *
     * Mantiene compat con el flujo legacy (is_active) mientras convive con status.
     */
    public function activate(): void
    {
        DB::transaction(function (): void {
            // Archivar la versión activa previa si es distinta a esta.
            static::forAgent($this->agent_key)
                ->where('id', '!=', $this->id)
                ->where(function (Builder $q): void {
                    $q->where('is_active', true)->orWhere('status', self::STATUS_ACTIVE);
                })
                ->update([
                    'is_active' => false,
                    'status' => self::STATUS_ARCHIVED,
                ]);

            $this->update([
                'is_active' => true,
                'status' => self::STATUS_ACTIVE,
                'owner_id' => null,
            ]);
        });

        Cache::forget(self::cacheKey($this->agent_key));
    }

    /**
     * Promueve este draft a activo: archiva el activo anterior, bumpea versión.
     */
    public function promote(): void
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new DomainException('Solo un draft puede ser promovido a activo.');
        }

        DB::transaction(function (): void {
            // Archivar la versión activa actual (si existe).
            static::forAgent($this->agent_key)
                ->where('id', '!=', $this->id)
                ->where(function (Builder $q): void {
                    $q->where('is_active', true)->orWhere('status', self::STATUS_ACTIVE);
                })
                ->update([
                    'is_active' => false,
                    'status' => self::STATUS_ARCHIVED,
                ]);

            $this->update([
                'version' => static::nextVersionFor($this->agent_key),
                'is_active' => true,
                'status' => self::STATUS_ACTIVE,
                'owner_id' => null,
            ]);
        });

        Cache::forget(self::cacheKey($this->agent_key));
    }

    /**
     * Descarta el draft (solo si el usuario es el owner o no hay owner).
     */
    public function discard(): void
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new DomainException('Solo un draft puede ser descartado con este método.');
        }

        $this->delete();
    }

    /**
     * Reasigna el ownership del draft a otro usuario.
     */
    public function takeControl(User $newOwner): void
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new DomainException('Solo un draft puede cambiar de owner.');
        }

        $this->update(['owner_id' => $newOwner->id]);
    }

    protected static function cacheKey(string $key): string
    {
        return "agent_prompt:{$key}";
    }
}
