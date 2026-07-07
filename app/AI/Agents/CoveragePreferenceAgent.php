<?php

namespace App\AI\Agents;

use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\CoveragePreferenceTool;
use App\AI\Tools\ProvideVehicleFactTool;
use App\AI\Tools\RevertStageTool;
use App\Models\AgentPrompt;
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

    /** @var array<int, string> */
    protected array $sharedBlocks = ['shared_style', 'shared_grounding'];

    public function __construct(
        private readonly CoveragePreferenceTool $tool,
        private readonly CheckCoverageRuleTool $coverageTool,
        private readonly ProvideVehicleFactTool $vehicleFactTool,
        private readonly RevertStageTool $revertStageTool,
    ) {}

    public function instructions(): Stringable|string
    {
        return AgentPrompt::compose($this->agentKey, $this->sharedBlocks);
    }

    /**
     * @return array<CoveragePreferenceTool|CheckCoverageRuleTool|ProvideVehicleFactTool|RevertStageTool>
     */
    public function tools(): iterable
    {
        return [$this->tool, $this->coverageTool, $this->vehicleFactTool, $this->revertStageTool];
    }
}
