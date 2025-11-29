<?php
namespace App\Factories;

use App\Contracts\AIProviderAdapterInterface;
use App\Adapters\OpenAI\AgentToolAdapter;
//use App\Adapters\Anthropic\ToolAdapter;
use InvalidArgumentException;

class ToolAdapterFactory
{
    /**
     * Crea una instancia del adaptador basada en el nombre del proveedor.
     * @param string $providerName El nombre del proveedor (ej: 'openai', 'anthropic')
     * @return AIProviderAdapterInterface
     * @throws InvalidArgumentException
     */
    public function make(string $providerName): AIProviderAdapterInterface
    {
        return match (strtolower($providerName)) {
            // Mapeamos OpenAI a AgentToolAdapter
            'openai', 'openai-chatkit' => app(AgentToolAdapter::class),
            //'anthropic', 'claude'      => app(AnthropicToolAdapter::class),
            default => throw new InvalidArgumentException("Proveedor de IA no soportado: {$providerName}"),
        };
    }
}