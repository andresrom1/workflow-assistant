<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentPrompt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentPromptController extends Controller
{
    /** @var array<string, string> */
    private const FILE_MAP = [
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

    public function index(): Response
    {
        $agents = collect(self::AGENT_LABELS)->map(function (string $label, string $key): array {
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

        return Inertia::render('Admin/AgentPrompts/Index', [
            'agents' => $agents,
        ]);
    }

    public function show(string $agentKey): Response
    {
        abort_unless(array_key_exists($agentKey, self::AGENT_LABELS), 404);

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

        return Inertia::render('Admin/AgentPrompts/Show', [
            'agentKey' => $agentKey,
            'agentLabel' => self::AGENT_LABELS[$agentKey],
            'activeVersion' => $active,
            'versions' => $versions,
        ]);
    }

    public function store(Request $request, string $agentKey): RedirectResponse
    {
        abort_unless(array_key_exists($agentKey, self::AGENT_LABELS), 404);

        $validated = $request->validate([
            'content' => ['required', 'string', 'min:20'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $newPrompt = AgentPrompt::create([
            'agent_key' => $agentKey,
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

    private function writePromptFile(string $agentKey, string $content): void
    {
        $file = self::FILE_MAP[$agentKey] ?? null;

        if (! $file) {
            return;
        }

        file_put_contents(resource_path("prompts/agents/{$file}"), $content);
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
