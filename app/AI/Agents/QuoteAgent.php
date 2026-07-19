<?php

namespace App\AI\Agents;

use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\GetQuoteTool;
use App\AI\Tools\RevertStageTool;
use App\AI\Tools\SiniestroGuidanceTool;
use App\Models\AgentPrompt;
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

    /** @var array<int, string> */
    protected array $sharedBlocks = ['shared_style', 'shared_grounding', 'shared_siniestro'];

    public function __construct(
        private readonly GetQuoteTool $tool,
        private readonly CheckCoverageRuleTool $coverageTool,
        private readonly RevertStageTool $revertStageTool,
        private readonly SiniestroGuidanceTool $siniestroTool,
    ) {}

    public function instructions(): Stringable|string
    {
        return AgentPrompt::compose($this->agentKey, $this->sharedBlocks);
    }

    /**
     * @return array<GetQuoteTool|CheckCoverageRuleTool|RevertStageTool|SiniestroGuidanceTool>
     */
    public function tools(): iterable
    {
        return [$this->tool, $this->coverageTool, $this->revertStageTool, $this->siniestroTool];
    }
}
