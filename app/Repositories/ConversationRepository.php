<?php

namespace App\Repositories;

use Illuminate\Support\Facades\Log;
use App\Models\Conversation;

class ConversationRepository
{
    public function findByThreadId(string $threadId): ?Conversation
    {
        return Conversation::where('thread_id', $threadId)->first();
    }

    public function createOrUpdate(string $threadId, int $customerId): Conversation
    {
        $existing = $this->findByThreadId($threadId);
        
        if ($existing && $existing->customer_id !== $customerId) {
            Log::warning('Thread ID conflict', [
                'thread_id' => $threadId,
                'old_customer' => $existing->customer_id,
                'new_customer' => $customerId
            ]);
            // Decide: ¿crear nueva conversación o actualizar?
        }
        
        return Conversation::updateOrCreate(
            ['thread_id' => $threadId],
            [
                'customer_id' => $customerId,
                'status' => 'active',
                'started_at' => $existing?->started_at ?? now(),
            ]
        );
    }

    public function attachVehicle(Conversation $conversation, int $vehicleId, bool $isPrimary = false): void
    {
        $conversation->vehicles()->syncWithoutDetaching([
            $vehicleId => ['is_primary' => $isPrimary]
        ]);
    }
}