<?php

namespace App\AI\Agents;

use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\GetQuoteTool;
use App\Models\AgentPrompt;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class QuoteAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    protected string $agentKey = 'quote_reception';

    public function __construct(
        private readonly GetQuoteTool $tool,
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
        return 'Tu tarea es mantener al cliente comprometido mientras se procesan las cotizaciones. '
            .'Hacé preguntas conversacionales sobre el vehículo o experiencias previas con seguros. '
            .'Cuando las cotizaciones estén listas, usá la herramienta disponible para verificarlo. '
            .'Respondé siempre en español, de forma concisa.';
    }

    /**
     * @return array<GetQuoteTool|CheckCoverageRuleTool>
     */
    public function tools(): iterable
    {
        return [$this->tool, $this->coverageTool];
    }
}
