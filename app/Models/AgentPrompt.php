<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AgentPrompt extends Model
{
    protected $fillable = [
        'agent_key',
        'type',
        'content',
        'version',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
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

    // ─── Activar versión (y limpiar cache) ───────────────────────────────────

    public function activate(): void
    {
        static::forAgent($this->agent_key)->update(['is_active' => false]);
        $this->update(['is_active' => true]);
        Cache::forget(self::cacheKey($this->agent_key));
    }

    protected static function cacheKey(string $key): string
    {
        return "agent_prompt:{$key}";
    }
}
