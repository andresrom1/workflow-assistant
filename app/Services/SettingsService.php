<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingsService
{
    const CACHE_KEY = 'system_settings_map';
    const CACHE_TTL = 3600; // 1 hora — file cache

    public function get(string $key, mixed $default = null): mixed
    {
        $map = $this->loadAll();
        return $map[$key] ?? $default;
    }

    /**
     * Guarda todos los settings de un grupo.
     * Solo actualiza campos editables — no toca type/label/description/is_secret.
     */
    public function saveGroup(string $group, array $data): void
    {
        DB::transaction(function () use ($group, $data) {
            foreach ($data as $key => $value) {
                SystemSetting::where('key', $key)
                    ->where('group', $group)
                    ->update(['value' => $value, 'updated_at' => now()]);
            }
        });

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Devuelve todos los settings agrupados para la vista.
     * Los campos secret se enmascaran para la UI (el controller decide).
     */
    public function allGrouped(): array
    {
        return SystemSetting::all()
            ->groupBy('group')
            ->map(fn ($group) => $group->values())
            ->toArray();
    }

    /**
     * Mapa simple key→valor (casteado) para uso interno en el código.
     */
    private function loadAll(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return SystemSetting::all()
                ->mapWithKeys(fn ($s) => [$s->key => $s->getCastValue()])
                ->toArray();
        });
    }
}