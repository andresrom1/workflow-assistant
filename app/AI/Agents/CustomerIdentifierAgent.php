<?php

namespace App\AI\Agents;

use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\IdentifyCustomerTool;
use App\Models\AgentPrompt;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class CustomerIdentifierAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    protected string $agentKey = 'customer_identifier';

    public function __construct(
        private readonly IdentifyCustomerTool $tool,
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
        return 'Tu única tarea es identificar al cliente en el sistema. '
            .'Pedile su nombre para registrarlo. '
            .'Una vez identificado, transicioná naturalmente a la siguiente etapa. '
            .'Respondé siempre en español, de forma concisa y amigable.';
    }

    /**
     * @return array<IdentifyCustomerTool|CheckCoverageRuleTool>
     */
    public function tools(): iterable
    {
        return [$this->tool, $this->coverageTool];
    }
}
