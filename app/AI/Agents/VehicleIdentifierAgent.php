<?php

namespace App\AI\Agents;

use App\AI\Tools\IdentifyVehicleTool;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class VehicleIdentifierAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(private readonly IdentifyVehicleTool $tool) {}

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        Tu única tarea es recopilar los datos del vehículo del cliente para iniciar la cotización.
        Necesitás: patente, marca, modelo, versión, año de fabricación, tipo de combustible y código postal.
        Pedí los datos faltantes de a uno o en bloque si el cliente no los proporcionó.
        Cuando tengas todos los datos, usá la herramienta disponible para registrar el vehículo.
        No respondas sobre temas fuera del registro del vehículo.
        Respondé siempre en español, de forma concisa y amigable.
        PROMPT;
    }

    /**
     * @return IdentifyVehicleTool[]
     */
    public function tools(): iterable
    {
        return [$this->tool];
    }
}
