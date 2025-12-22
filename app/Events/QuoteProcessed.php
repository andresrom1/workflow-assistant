<?php

namespace App\Events;

use App\Models\Quote;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class QuoteProcessed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Quote $quote
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        // Canal Seguro: private-chat.{thread_id}
        // Usamos loadMissing para asegurar que tenemos la relación
        $this->quote->loadMissing('conversation');
        $channelName = 'chat.' . $this->quote->conversation->external_conversation_id;
        
        Log::info(__METHOD__.__LINE__." [Event] Broadcasting QuoteProcessed en canal: private-{$channelName}");

        return [
            new PrivateChannel($channelName),
        ];
    }

    public function broadcastAs(): string
    {
        return 'quote.processed';
    }

    public function broadcastWith(): array
    {
        $this->quote->load('alternatives');

        // Mapeo limpio de alternativas para la IA
        // $aiContext = $this->quote->alternatives->map(function ($alt) {
        //     return [
        //         'aseguradora' => $alt->aseguradora,
        //         'plan'        => $alt->descripcion,
        //         'precio'      => (float) $alt->precio,
        //         'moneda'      => $alt->moneda,
        //         'cobertura'   => $alt->normalized_grade,
        //         'features'    => $alt->features_tags,
        //     ];
        // })->toArray();

        //$aiContext = $this->quote->raw_response;

        // Estructura Final del JSON WebSocket
        return [
            'type'     => 'QUOTE_READY',
            'quote_id' => $this->quote->id,
            'summary'  => "Se generaron {$this->quote->alternatives->count()} opciones.",
            
            // Flag para el Frontend
            'requires_ai_injection' => true,
            
            // Payload para System Injection
            'ai_payload' => [
                'event' => 'QUOTES_RECEIVED',
                'source' => 'backend',
                'data'  => $this->quote->raw_response
            ]
        ];
    }
}
