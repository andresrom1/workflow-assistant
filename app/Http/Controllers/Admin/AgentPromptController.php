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

    public function show(string $agentKey): Response
    {
        abort_unless($this->isKnownKey($agentKey), 404);

        $versions = AgentPrompt::forAgent($agentKey)
            ->orderByDesc('version')
            ->get()
            ->map(fn (AgentPrompt $p): array => [
                'id' => $p->id,
                'version' => $p->version,
                'is_active' => $p->is_active,
                'notes' => $p->notes,
                'content' => $p->content,
                'created_at' => $p->created_at->toIso8601String(),
            ]);

        $active = $versions->firstWhere('is_active', true);
        $type = $this->typeFor($agentKey);

        $payload = [
            'agentKey' => $agentKey,
            'agentLabel' => $this->labelFor($agentKey),
            'type' => $type,
            'activeVersion' => $active,
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
