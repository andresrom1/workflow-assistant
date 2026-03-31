<?php

namespace App\AI\Agents;

use App\AI\Tools\IdentifyCustomerTool;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class CustomerIdentifierAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(private readonly IdentifyCustomerTool $tool) {}

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        Tu única tarea es identificar al cliente en el sistema.
        Pedile su email, número de teléfono o DNI/CUIT para encontrarlo.
        Una vez que tengas un identificador válido, usá la herramienta disponible.
        Si la identificación es exitosa, confirmale su nombre al cliente.
        No respondas sobre temas fuera de la identificación del cliente.
        Respondé siempre en español, de forma concisa y amigable.
        PROMPT;
    }

    /**
     * @return IdentifyCustomerTool[]
     */
    public function tools(): iterable
    {
        return [$this->tool];
    }
}
