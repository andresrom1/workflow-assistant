<?php

namespace App\AI\Agents;

use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\CheckoutTool;
use App\Models\AgentPrompt;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class CheckoutAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    protected string $agentKey = 'checkout_closer';

    public function __construct(
        private readonly CheckoutTool $tool,
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
        return 'Tu tarea es presentar las cotizaciones disponibles y ayudar al cliente a elegir. '
            .'Presentá las mejores 2-3 opciones de forma clara. '
            .'Cuando el cliente confirme cuál alternativa desea, usá la herramienta disponible '
            .'pasando el quoteId y quote_alternative_id correspondientes. '
            .'Respondé siempre en español, de forma concisa y amigable.';
    }

    /**
     * @return array<CheckoutTool|CheckCoverageRuleTool>
     */
    public function tools(): iterable
    {
        return [$this->tool, $this->coverageTool];
    }
}
