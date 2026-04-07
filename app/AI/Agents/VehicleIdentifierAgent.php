<?php

namespace App\AI\Agents;

use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\IdentifyVehicleTool;
use App\Models\AgentPrompt;
use Illuminate\Support\Facades\Cache;
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

    public function __construct(
        private readonly IdentifyVehicleTool $tool,
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
        return 'Tu única tarea es recopilar los datos del vehículo del cliente para iniciar la cotización. '
            .'Necesitás: patente, marca, modelo, versión, año de fabricación, tipo de combustible y código postal. '
            .'Pedí los datos faltantes de a uno o en bloque si el cliente no los proporcionó. '
            .'Cuando tengas todos los datos, usá la herramienta disponible para registrar el vehículo. '
            .'Respondé siempre en español, de forma concisa y amigable.';
    }

    /**
     * @return array<IdentifyVehicleTool|CheckCoverageRuleTool>
     */
    public function tools(): iterable
    {
        return [$this->tool, $this->coverageTool];
    }
}
