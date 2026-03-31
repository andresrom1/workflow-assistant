<?php

namespace App\AI\Agents;

use App\AI\Tools\CheckoutTool;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class CheckoutAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(private readonly CheckoutTool $tool) {}

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        Tu tarea es ayudar al cliente a completar la contratación de su póliza.
        Cuando el cliente confirme qué alternativa desea contratar, usá la herramienta disponible
        para generar el link de checkout.
        Compartí el link de forma clara y explicale que ahí completa sus datos de pago.
        Respondé siempre en español, de forma concisa y amigable.
        PROMPT;
    }

    /**
     * @return CheckoutTool[]
     */
    public function tools(): iterable
    {
        return [$this->tool];
    }
}
