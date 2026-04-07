<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AgentPrompt extends Model
{
    protected $fillable = [
        'agent_key',
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

    // ─── Helpers estáticos ───────────────────────────────────────────────────

    public static function activeFor(string $key): ?self
    {
        return static::forAgent($key)->active()->first();
    }

    public static function nextVersionFor(string $key): int
    {
        return (static::forAgent($key)->max('version') ?? 0) + 1;
    }

    // ─── Activar versión (y limpiar cache) ───────────────────────────────────

    public function activate(): void
    {
        static::forAgent($this->agent_key)->update(['is_active' => false]);
        $this->update(['is_active' => true]);
        Cache::forget("agent_prompt:{$this->agent_key}");
    }
}
