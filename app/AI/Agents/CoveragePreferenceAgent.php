<?php

namespace App\AI\Agents;

use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\CoveragePreferenceTool;
use App\Models\AgentPrompt;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class CoveragePreferenceAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    protected string $agentKey = 'coverage_preference';

    public function __construct(
        private readonly CoveragePreferenceTool $tool,
        private readonly CheckCoverageRuleTool $coverageTool,
    ) {}

    public function instructions(): Stringable|string
    {
        return Cache::rememberForever(
            "agent_prompt:{$this->agentKey}",
            fn () => AgentPrompt::activeFor($this->agentKey)?->content ?? $this->fallbackInstructions()
        );
    }

    protected function fallbackInstructions(): string
    {
        return 'Tu tarea es identificar la cobertura que necesita el cliente y registrar su preferencia. '
            .'Las coberturas son: A (Responsabilidad Civil), B (Robo/Incendio Total), '
            .'C (Terceros Completos) y D (Todo Riesgo). '
            .'Explicá brevemente las diferencias cuando el cliente lo necesite. '
            .'Una vez que el cliente elija, usá la herramienta disponible pasando coverage_code (A/B/C/D), patente y reasoning. '
            .'Respondé siempre en español, de forma concisa.';
    }

    /**
     * @return array<CoveragePreferenceTool|CheckCoverageRuleTool>
     */
    public function tools(): iterable
    {
        return [$this->tool, $this->coverageTool];
    }
}
