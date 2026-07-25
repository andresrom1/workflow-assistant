<?php

namespace App\AI\Agents;

use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\DeclineDniTool;
use App\AI\Tools\IdentifyCustomerTool;
use App\AI\Tools\SiniestroGuidanceTool;
use App\Models\AgentPrompt;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[UseCheapestModel]
class CustomerIdentifierAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    protected string $agentKey = 'customer_identifier';

    /** @var array<int, string> */
    protected array $sharedBlocks = ['shared_style', 'shared_grounding', 'shared_siniestro'];

    public function __construct(
        private readonly IdentifyCustomerTool $tool,
        private readonly CheckCoverageRuleTool $coverageTool,
        private readonly SiniestroGuidanceTool $siniestroTool,
        private readonly DeclineDniTool $declineDniTool,
    ) {}

    public function instructions(): Stringable|string
    {
        return AgentPrompt::compose($this->agentKey, $this->sharedBlocks);
    }

    /**
     * @return array<IdentifyCustomerTool|CheckCoverageRuleTool|SiniestroGuidanceTool|DeclineDniTool>
     */
    public function tools(): iterable
    {
        return [$this->tool, $this->coverageTool, $this->siniestroTool, $this->declineDniTool];
    }
}
