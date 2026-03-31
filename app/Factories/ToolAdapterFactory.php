<?php

namespace App\Factories;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Adapters\OpenAI\AgentToolAdapter;
use App\Contracts\AIProviderAdapterInterface;
use InvalidArgumentException;

class ToolAdapterFactory
{
    /**
     * Crea una instancia del adaptador basada en el nombre del proveedor.
     *
     * @param  string  $providerName  El nombre del proveedor (ej: 'openai', 'whatsapp')
     *
     * @throws InvalidArgumentException
     */
    public function make(string $providerName): AIProviderAdapterInterface
    {
        return match (strtolower($providerName)) {
            'openai', 'openai-chatkit' => app(AgentToolAdapter::class),
            'whatsapp' => app(WhatsAppAdapter::class),
            default => throw new InvalidArgumentException("Proveedor de IA no soportado: {$providerName}"),
        };
    }
}
