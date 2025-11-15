<?php
// app/Repositories/ConversationRepository.php

namespace App\Repositories;

use App\Models\Conversation;

class ConversationRepository
{
    public function findByThreadId(string $threadId): ?Conversation
    {
        return Conversation::where('thread_id', $threadId)->first();
    }

    public function createOrUpdate(string $threadId, int $customerId): Conversation
    {
        return Conversation::updateOrCreate(
            ['thread_id' => $threadId],
            [
                'customer_id' => $customerId,
                'status' => 'identified',
                'started_at' => now(),
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