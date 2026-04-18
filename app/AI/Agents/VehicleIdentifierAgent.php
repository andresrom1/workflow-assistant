<?php

namespace App\AI\Agents;

use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\IdentifyVehicleTool;
use App\Models\AgentPrompt;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class VehicleIdentifierAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    protected string $agentKey = 'vehicle_identifier';

    /** @var array<int, string> */
    protected array $sharedBlocks = ['shared_style', 'shared_grounding'];

    public function __construct(
        private readonly IdentifyVehicleTool $tool,
        private readonly CheckCoverageRuleTool $coverageTool,
    ) {}

    public function instructions(): Stringable|string
    {
        return AgentPrompt::compose($this->agentKey, $this->sharedBlocks);
    }

    /**
     * @return array<IdentifyVehicleTool|CheckCoverageRuleTool>
     */
    public function tools(): iterable
    {
        return [$this->tool, $this->coverageTool];
    }
}
