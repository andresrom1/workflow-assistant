<?php

namespace App\Events;

use App\Models\Quote;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
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
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $this->quote->loadMissing('conversation');
        $channelName = 'chat.'.$this->quote->session_uuid;

        Log::info("[QuoteProcessed] Broadcasting en canal: {$channelName}", ['quote_id' => $this->quote->id]);

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
            'type' => 'QUOTE_READY',
            'quote_id' => $this->quote->id,
            'requires_ai_injection' => true, // Flag para el Frontend
            'thread_id' => $this->quote->conversation->external_conversation_id,
        ];
    }
}
