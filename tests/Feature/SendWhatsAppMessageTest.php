<?php

namespace Tests\Feature;

use App\Exceptions\WhatsAppSpamLimitException;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendWhatsAppMessageTest extends TestCase
{
    use RefreshDatabase;

    private string $waId = '5491112345678';

    private string $phoneNumberId = '123456789';

    #[Test]
    public function it_calls_outbound_service_with_correct_parameters(): void
    {
        $conversation = Conversation::factory()->create();

        $waService = $this->mock(WhatsAppOutboundService::class);
        $waService->shouldReceive('sendMessage')
            ->once()
            ->with($this->waId, 'Hola, te ayudo con tu cotización', $this->phoneNumberId, $conversation->id)
            ->andReturn(['messages' => [['id' => 'wamid.out001']]]);

        SendWhatsAppMessage::dispatchSync($this->waId, 'Hola, te ayudo con tu cotización', $this->phoneNumberId, $conversation->id);
    }

    #[Test]
    public function it_calls_outbound_service_without_conversation_id(): void
    {
        $waService = $this->mock(WhatsAppOutboundService::class);
        $waService->shouldReceive('sendMessage')
            ->once()
            ->with($this->waId, 'Mensaje de prueba', $this->phoneNumberId, null)
            ->andReturn([]);

        SendWhatsAppMessage::dispatchSync($this->waId, 'Mensaje de prueba', $this->phoneNumberId);
    }

    #[Test]
    public function it_does_not_retry_on_spam_limit_exception(): void
    {
        $waService = $this->mock(WhatsAppOutboundService::class);

        // sendMessage se llama exactamente una vez — no hay reintentos
        $waService->shouldReceive('sendMessage')
            ->once()
            ->andThrow(new WhatsAppSpamLimitException('Rate limit exceeded'));

        // dispatchSync con $this->fail() no re-lanza en tests; verificamos
        // que el servicio fue invocado exactamente una vez (sin reintentos).
        SendWhatsAppMessage::dispatchSync($this->waId, 'Mensaje', $this->phoneNumberId);
    }
}
