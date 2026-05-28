<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentPrompt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AgentPromptController extends Controller
{
    /** @var array<string, string> */
    private const AGENT_FILE_MAP = [
        'customer_identifier' => 'CustomerIdentifierAgent.md',
        'vehicle_identifier' => 'VehicleIdentifierAgent.md',
        'coverage_preference' => 'CoveragePreferenceAgent.md',
        'quote_reception' => 'QuoteAgent.md',
        'checkout_closer' => 'CheckoutAgent.md',
        'coverage_check' => 'CoverageCheckAgent.md',
    ];

    /** @var array<string, string> */
    private const AGENT_LABELS = [
        'customer_identifier' => 'CustomerIdentifierAgent',
        'vehicle_identifier' => 'VehicleIdentifierAgent',
        'coverage_preference' => 'CoveragePreferenceAgent',
        'quote_reception' => 'QuoteAgent',
        'checkout_closer' => 'CheckoutAgent',
        'coverage_check' => 'CoverageCheckAgent',
    ];

    /** @var array<string, string> */
    private const SHARED_FILE_MAP = [
        'shared_style' => 'shared_style.md',
        'shared_grounding' => 'shared_grounding.md',
    ];

    /** @var array<string, string> */
    private const SHARED_LABELS = [
        'shared_style' => 'Estilo compartido',
        'shared_grounding' => 'Grounding compartido',
    ];

    public function index(): Response
    {
        return Inertia::render('Admin/AgentPrompts/Index', [
            'agents' => $this->buildPromptList(self::AGENT_LABELS),
            'sharedBlocks' => $this->buildPromptList(self::SHARED_LABELS),
        ]);
    }

    public function show(Request $request, string $agentKey): Response
    {
        abort_unless($this->isKnownKey($agentKey), 404);

        $currentUserId = (int) $request->user()->id;

        $versions = AgentPrompt::forAgent($agentKey)
            ->with('owner:id,name')
            ->orderByDesc('version')
            ->get()
            ->map(fn (AgentPrompt $p): array => $this->serializeVersion($p, $currentUserId));

        $active = $versions->firstWhere('is_active', true);
        $draft = $versions->firstWhere('status', AgentPrompt::STATUS_DRAFT);
        $type = $this->typeFor($agentKey);

        $payload = [
            'agentKey' => $agentKey,
            'agentLabel' => $this->labelFor($agentKey),
            'type' => $type,
            'activeVersion' => $active,
            'draft' => $draft,
            'versions' => $versions,
        ];

        if ($type === 'agent') {
            $payload['composedPreview'] = AgentPrompt::compose(
                $agentKey,
                ['shared_style', 'shared_grounding']
            );
            $payload['inheritedBlocks'] = array_keys(self::SHARED_LABELS);
        }

        return Inertia::render('Admin/AgentPrompts/Show', $payload);
    }

    /**
     * Crea un draft a partir de la versión activa. Solo puede existir un draft por agent_key.
     */
    public function createDraft(Request $request, string $agentKey): RedirectResponse
    {
        abort_unless($this->isKnownKey($agentKey), 404);

        $existing = AgentPrompt::draftFor($agentKey);
        if ($existing instanceof AgentPrompt) {
            return redirect()
                ->route('admin.agent-prompts.show', $agentKey)
                ->with('error', 'Ya existe un draft en curso. Tomá el control o descartalo antes de crear uno nuevo.');
        }

        $active = AgentPrompt::activeFor($agentKey);
        $baseContent = $active instanceof AgentPrompt
            ? $active->content
            : (string) @file_get_contents($this->resolvePromptPath($agentKey));

        AgentPrompt::create([
            'agent_key' => $agentKey,
            'type' => $this->typeFor($agentKey),
            'content' => $baseContent,
            'version' => AgentPrompt::nextVersionFor($agentKey),
            'is_active' => false,
            'status' => AgentPrompt::STATUS_DRAFT,
            'owner_id' => $request->user()->id,
            'parent_version_id' => $active?->id,
            'notes' => null,
        ]);

        return redirect()
            ->route('admin.agent-prompts.show', $agentKey)
            ->with('success', 'Draft creado. Editá y probá hasta que quieras promoverlo.');
    }

    /**
     * Actualiza el contenido/notas de un draft existente. Solo el owner puede editar.
     */
    public function updateDraft(Request $request, AgentPrompt $agentPrompt): RedirectResponse
    {
        abort_unless($agentPrompt->status === AgentPrompt::STATUS_DRAFT, 409, 'No es un draft.');
        $this->assertIsDraftOwner($agentPrompt, $request);

        $validated = $request->validate([
            'content' => ['required', 'string', 'min:20'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $agentPrompt->update([
            'content' => $validated['content'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return redirect()
            ->route('admin.agent-prompts.show', $agentPrompt->agent_key)
            ->with('success', 'Draft actualizado.');
    }

    /**
     * Promueve el draft a activo: archiva el activo anterior, bumpea versión, invalida caché.
     */
    public function promoteDraft(Request $request, AgentPrompt $agentPrompt): RedirectResponse
    {
        abort_unless($agentPrompt->status === AgentPrompt::STATUS_DRAFT, 409, 'No es un draft.');
        $this->assertIsDraftOwner($agentPrompt, $request);

        $agentPrompt->promote();

        $this->writePromptFile($agentPrompt->agent_key, $agentPrompt->content);

        return redirect()
            ->route('admin.agent-prompts.show', $agentPrompt->agent_key)
            ->with('success', "Draft promovido a v{$agentPrompt->version} activa.");
    }

    /**
     * Reasigna el ownership del draft al usuario autenticado.
     */
    public function takeDraftControl(Request $request, AgentPrompt $agentPrompt): RedirectResponse
    {
        abort_unless($agentPrompt->status === AgentPrompt::STATUS_DRAFT, 409, 'No es un draft.');

        $agentPrompt->takeControl($request->user());

        return redirect()
            ->route('admin.agent-prompts.show', $agentPrompt->agent_key)
            ->with('success', 'Tomaste el control del draft.');
    }

    /**
     * Descarta un draft. Solo el owner puede descartar.
     */
    public function discardDraft(Request $request, AgentPrompt $agentPrompt): RedirectResponse
    {
        abort_unless($agentPrompt->status === AgentPrompt::STATUS_DRAFT, 409, 'No es un draft.');
        $this->assertIsDraftOwner($agentPrompt, $request);

        $agentKey = $agentPrompt->agent_key;
        $agentPrompt->discard();

        return redirect()
            ->route('admin.agent-prompts.show', $agentKey)
            ->with('success', 'Draft descartado.');
    }

    public function store(Request $request, string $agentKey): RedirectResponse
    {
        abort_unless($this->isKnownKey($agentKey), 404);

        $validated = $request->validate([
            'content' => ['required', 'string', 'min:20'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $newPrompt = AgentPrompt::create([
            'agent_key' => $agentKey,
            'type' => $this->typeFor($agentKey),
            'content' => $validated['content'],
            'version' => AgentPrompt::nextVersionFor($agentKey),
            'is_active' => false,
            'notes' => $validated['notes'] ?? null,
        ]);

        $newPrompt->activate();

        $this->writePromptFile($agentKey, $validated['content']);

        return redirect()
            ->route('admin.agent-prompts.show', $agentKey)
            ->with('success', 'Nueva versión guardada y activada correctamente.');
    }

    public function activate(AgentPrompt $agentPrompt): RedirectResponse
    {
        $agentPrompt->activate();

        return redirect()
            ->route('admin.agent-prompts.show', $agentPrompt->agent_key)
            ->with('success', "Versión {$agentPrompt->version} restaurada correctamente.");
    }

    /**
     * Lectura JSON de una versión específica — usado por el slide-over que muestra
     * qué prompt corrió en un turn histórico.
     *
     * @return array{id: int, agent_key: string, agent_label: string, version: int, status: string, is_active: bool, notes: string|null, content: string, created_at: string}
     */
    public function view(AgentPrompt $agentPrompt): array
    {
        return [
            'id' => $agentPrompt->id,
            'agent_key' => $agentPrompt->agent_key,
            'agent_label' => $this->labelFor($agentPrompt->agent_key),
            'version' => $agentPrompt->version,
            'status' => $agentPrompt->status ?? ($agentPrompt->is_active ? AgentPrompt::STATUS_ACTIVE : AgentPrompt::STATUS_ARCHIVED),
            'is_active' => (bool) $agentPrompt->is_active,
            'notes' => $agentPrompt->notes,
            'content' => $agentPrompt->content,
            'created_at' => $agentPrompt->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return Collection<int, array{key: string, label: string, version: int|null, updated_at: string|null, preview: string|null, has_prompt: bool}>
     */
    private function buildPromptList(array $labels)
    {
        return collect($labels)->map(function (string $label, string $key): array {
            $active = AgentPrompt::activeFor($key);

            return [
                'key' => $key,
                'label' => $label,
                'version' => $active?->version,
                'updated_at' => $active?->updated_at?->toIso8601String(),
                'preview' => $active instanceof AgentPrompt ? $this->extractPreview($active->content) : null,
                'has_prompt' => $active instanceof AgentPrompt,
            ];
        })->values();
    }

    private function isKnownKey(string $key): bool
    {
        return array_key_exists($key, self::AGENT_LABELS) || array_key_exists($key, self::SHARED_LABELS);
    }

    private function assertIsDraftOwner(AgentPrompt $prompt, Request $request): void
    {
        if ($prompt->owner_id !== null && (int) $prompt->owner_id !== (int) $request->user()->id) {
            abort(403, 'Este draft pertenece a otro admin. Tomá el control primero.');
        }
    }

    /**
     * @return array{id: int, version: int, is_active: bool, status: string, notes: string|null, content: string, created_at: string, owner: array{id: int, name: string}|null, is_mine: bool, parent_version_id: int|null}
     */
    private function serializeVersion(AgentPrompt $p, int $currentUserId): array
    {
        return [
            'id' => $p->id,
            'version' => $p->version,
            'is_active' => $p->is_active,
            'status' => $p->status ?? ($p->is_active ? AgentPrompt::STATUS_ACTIVE : AgentPrompt::STATUS_ARCHIVED),
            'notes' => $p->notes,
            'content' => $p->content,
            'created_at' => $p->created_at->toIso8601String(),
            'owner' => $p->owner ? ['id' => $p->owner->id, 'name' => $p->owner->name] : null,
            'is_mine' => $p->owner_id !== null && (int) $p->owner_id === $currentUserId,
            'parent_version_id' => $p->parent_version_id,
        ];
    }

    private function resolvePromptPath(string $agentKey): string
    {
        if (isset(self::AGENT_FILE_MAP[$agentKey])) {
            return resource_path('prompts/agents/'.self::AGENT_FILE_MAP[$agentKey]);
        }

        return resource_path('prompts/shared/'.(self::SHARED_FILE_MAP[$agentKey] ?? ''));
    }

    private function typeFor(string $key): string
    {
        return array_key_exists($key, self::SHARED_LABELS) ? 'shared' : 'agent';
    }

    private function labelFor(string $key): string
    {
        return self::AGENT_LABELS[$key] ?? self::SHARED_LABELS[$key];
    }

    private function writePromptFile(string $agentKey, string $content): void
    {
        if (isset(self::AGENT_FILE_MAP[$agentKey])) {
            $path = resource_path('prompts/agents/'.self::AGENT_FILE_MAP[$agentKey]);
        } elseif (isset(self::SHARED_FILE_MAP[$agentKey])) {
            $dir = resource_path('prompts/shared');

            if (! is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }

            $path = $dir.'/'.self::SHARED_FILE_MAP[$agentKey];
        } else {
            return;
        }

        file_put_contents($path, $content);
    }

    private function extractPreview(string $content): string
    {
        $lines = array_filter(
            array_map(trim(...), explode("\n", $content)),
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')
        );

        $preview = implode(' ', array_slice(array_values($lines), 0, 2));

        return mb_strlen($preview) > 160
            ? mb_substr($preview, 0, 157).'...'
            : $preview;
    }
}
