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
        Log::info(__METHOD__.__LINE__." [Event] Valor de Quote: {$this->quote}");
        $this->quote->loadMissing('conversation');
        $channelName = 'chat.' . $this->quote->session_uuid;
        
        Log::info(__METHOD__.__LINE__." [Event] Broadcasting QuoteProcessed en canal: {$channelName}");

        return [
            new Channel($channelName), // Canal público
        ];
    }

    public function broadcastAs(): string
    {
        return 'quote.processed';
    }

    public function broadcastWith(): array
    {
        $this->quote->load('alternatives');

        return [
            'type'     => 'QUOTE_READY',
            'quote_id' => $this->quote->id,
            'requires_ai_injection' => true, // Flag para el Frontend  
            'thread_id' => $this->quote->conversation->external_conversation_id,     
        ];
    }
}
