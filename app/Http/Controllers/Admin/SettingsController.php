<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(): Response
    {
        $raw = SystemSetting::all()->groupBy('group');

        // Construir estructura para la vista — secrets se envían enmascarados
        $groups = $raw->map(fn ($items, $groupKey) => [
            'key' => $groupKey,
            'label' => $this->groupLabel($groupKey),
            'items' => $items->map(fn ($s): array => [
                'key' => $s->key,
                'label' => $s->label,
                'description' => $s->description,
                'type' => $s->type,
                'is_secret' => $s->is_secret,
                // Secrets: valor real para editar, pero el front muestra ●●● por default
                'value' => $s->value,
                'updated_at' => $s->updated_at?->toIso8601String(),
            ])->values(),
        ])->values();

        return Inertia::render('Admin/Settings/Index', [
            'groups' => $groups,
        ]);
    }

    public function updateGroup(Request $request, string $group): RedirectResponse
    {
        $items = SystemSetting::where('group', $group)->get()->keyBy('key');
        abort_if($items->isEmpty(), 404);

        // El frontend envía { "checkout.required_photos": "7", ... }
        $incoming = $request->input('settings', []);

        foreach ($incoming as $key => $value) {
            abort_unless($items->has($key), 422, "Key inválida: {$key}");

            $setting = $items[$key];

            // Validación por tipo
            if ($setting->type === 'integer' && ! is_numeric($value)) {
                return back()->withErrors(["El campo '{$setting->label}' debe ser un número."]);
            }

            // No guardar secrets vacíos (significaría "borrar la key")
            if ($setting->is_secret && empty($value)) {
                continue;
            }
        }

        $this->settings->saveGroup($group, $incoming);

        return back()->with('success', "Configuración de «{$this->groupLabel($group)}» guardada.");
    }

    private function groupLabel(string $group): string
    {
        return [
            'pas' => 'Cotización',
            'checkout' => 'Checkout',
            'poliza_api' => 'API de Emisión',
        ][$group] ?? ucfirst($group);
    }
}
