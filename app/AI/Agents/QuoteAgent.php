<?php

namespace App\AI\Agents;

use App\AI\Tools\GetQuoteTool;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class QuoteAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(private readonly GetQuoteTool $tool) {}

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        Tu tarea es obtener y presentar las cotizaciones disponibles para el vehículo del cliente.
        Cuando el cliente esté listo, usá la herramienta disponible para consultar las alternativas.
        Si la cotización aún no está procesada, informale que en breve recibirá las opciones.
        Al mostrar cotizaciones, destacá aseguradora, cobertura y precio de cada alternativa.
        Usá *negrita* para nombres de aseguradoras y precios.
        Respondé siempre en español, de forma concisa.
        PROMPT;
    }

    /**
     * @return GetQuoteTool[]
     */
    public function tools(): iterable
    {
        return [$this->tool];
    }
}
