<?php

namespace App\Adapters\N8nWhatsapp;

use App\Contracts\AIProviderAdapterInterface;
use App\Models\Conversation;

class AgentToolAdapter implements AIProviderAdapterInterface
{
    public function handleToolCall(array $_payload, string $_toolName): array
    {
        throw new \RuntimeException('N8n WhatsApp adapter not yet implemented.');
    }

    public function identifyCustomer(array $_data, Conversation $_conversation): array
    {
        throw new \RuntimeException('N8n WhatsApp adapter not yet implemented.');
    }

    public function identifyVehicle(array $_data, Conversation $_conversation): array
    {
        throw new \RuntimeException('N8n WhatsApp adapter not yet implemented.');
    }

    public function coveragePreference(array $_data, Conversation $_conversation): array
    {
        throw new \RuntimeException('N8n WhatsApp adapter not yet implemented.');
    }

    public function getQuote(array $_data): array
    {
        throw new \RuntimeException('N8n WhatsApp adapter not yet implemented.');
    }

    public function checkout(array $_data, Conversation $_conversation): array
    {
        throw new \RuntimeException('N8n WhatsApp adapter not yet implemented.');
    }
}
