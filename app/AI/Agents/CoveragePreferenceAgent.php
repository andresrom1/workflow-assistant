<?php

namespace App\AI\Agents;

use App\AI\Tools\CoveragePreferenceTool;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class CoveragePreferenceAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(private readonly CoveragePreferenceTool $tool) {}

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        Tu tarea es explicar las opciones de cobertura disponibles y registrar la preferencia del cliente.
        Las opciones son: terceros (cobertura básica), terceros_completo (incluye robo e incendio)
        y todo_riesgo (cobertura total).
        Explicá brevemente cada opción y sus diferencias cuando el cliente lo necesite.
        Una vez que el cliente elija, usá la herramienta disponible para registrar su preferencia.
        Respondé siempre en español, de forma concisa. Usá *negrita* para destacar nombres de coberturas.
        PROMPT;
    }

    /**
     * @return CoveragePreferenceTool[]
     */
    public function tools(): iterable
    {
        return [$this->tool];
    }
}
