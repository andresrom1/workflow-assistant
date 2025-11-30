<?php

namespace App\Repositories;

use Illuminate\Support\Facades\Log;
use App\Models\Conversation;
use App\Traits\ConditionalLogger;

class ConversationRepository
{
    use ConditionalLogger;

    public function findByThreadId(string $threadId): ?Conversation
    {
        return Conversation::where('external_conversation_id', $threadId)->first();
    }

    /**
     * Summary of findOrCreateByThreadId
     * @param string $threadId
     * @return Conversation
     */
    public function findOrCreateByThreadId(string $threadId): Conversation
    {
        $this->logConversation('Entrada a findOrCreateByThreadId con threadId: ' ,['thread_id'=>$threadId]);
        
        $conversation = Conversation::where('external_conversation_id', $threadId)->firstOrCreate(
            ['external_conversation_id' => $threadId],
            [
                'external_conversation_id' => $threadId,
                'status' => 'active',
            ]
        );

        $this->logConversation('Conversación encontrada o creada', ['conversation_id' => $conversation->id]);
        return $conversation;
    }

    /**
     * Summary of linkCustomer
     * @param int $conversationId
     * @param int $customerId
     * @return void
     */
    public function linkCustomer(int $conversationId, int $customerId): void
    {
        Conversation::where('id', $conversationId)->update([
            'customer_id' => $customerId,
            'last_message_at' => now(),
        ]);
    }

    public function createOrUpdate(string $threadId, int $customerId): Conversation
    {
        $this->logConversation('Entrada a createOrUpdate con threadId: ' ,['thread_id'=>$threadId]);
        $existing = $this->findByThreadId($threadId);
        
        if ($existing && $existing->customer_id !== $customerId) {
            Log::warning('Thread ID conflict', [
                'external_conversation_id' => $threadId,
                'old_customer' => $existing->customer_id,
                'new_customer' => $customerId
            ]);
            // Decide: ¿crear nueva conversación o actualizar?
        }
        
        return Conversation::updateOrCreate(
            ['external_conversation_id' => $threadId],
            [
                'customer_id' => $customerId,
                'status' => 'active',
            ]
        );
    }

    public function attachVehicle(Conversation $conversation, int $vehicleId, bool $isPrimary = false): void
    {
        $conversation->vehicles()->syncWithoutDetaching([
            $vehicleId => ['is_primary' => $isPrimary]
        ]);
    }

    public function findActiveByOpenAIUserId(string $threadId): ?Conversation
    {
        Log::info(__METHOD__ . __LINE__ . ' Buscando conversación activa', ['external_conversation_id' => $threadId]);
        return Conversation::where('external_conversation_id', $threadId)
            ->where('status', 'active')
            ->latest('last_activity')
            ->first();
        // return Conversation::where('openai_user_id', $openaiUserId)
        //     ->where('status', 'active')
        //     ->latest('last_activity')
        //     ->first();
    }

    public function updateActivity(string $openaiUserId): void
    {
        Conversation::where('external_user_id', $openaiUserId)
            ->where('status', 'active')
            ->update(['last_message_at' => now()]);
    }
}