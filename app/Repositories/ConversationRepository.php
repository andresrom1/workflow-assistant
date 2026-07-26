<?php

namespace App\Repositories;

use App\Models\Conversation;
use App\Models\CoveragePreference;
use App\Traits\ConditionalLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class ConversationRepository
{
    use ConditionalLogger;

    public function findByThreadId(string $threadId): ?Conversation
    {
        return Conversation::where('external_conversation_id', $threadId)->first();
    }

    /**
     * Busca una conversación por su identificador externo de usuario (ej: BSUID de WhatsApp).
     * Agnóstico al canal — aplica a cualquier identificador estable de usuario.
     */
    public function findByExtUserId(string $extUserId): ?Conversation
    {
        return Conversation::where('ext_user_id', $extUserId)
            ->where('status', '!=', 'archived')
            ->first();
    }

    /**
     * Busca la conversación viva del usuario por su BSUID (identidad estable del canal), o la
     * crea anclada en ese BSUID. Reemplaza el find-or-create por teléfono: el BSUID siempre
     * está presente (desde abr-2026) y no cambia si el usuario gana/pierde el teléfono visible.
     * El índice único parcial en `ext_user_id` (activas) es el backstop ante una carrera.
     */
    public function findOrCreateByExtUserId(string $extUserId, string $channel): Conversation
    {
        return $this->findByExtUserId($extUserId) ?? Conversation::create([
            'ext_user_id' => $extUserId,
            'channel' => $channel,
            'status' => 'active',
            'last_message_at' => now(),
        ]);
    }

    /**
     * Summary of findOrCreateByExternalConversationId
     *
     * @param  string  $externalId  El ID externo de la conversación (OpenAi: thread_id)
     * @param  string  $channel  El channel del cual proviene ['web', 'whatsapp', 'telegram', etc...]
     * @param  array|null  $metadata
     */
    public function findOrCreateByExternalId(string $externalId, $channel, $metadata = null): Conversation
    {
        $this->logConversation(
            'Entrada a findOrCreateById con external_conversation_id: ',
            ['external_conversation_id' => $externalId]
        );

        return Conversation::where('external_conversation_id', $externalId)->firstOrCreate(
            ['external_conversation_id' => $externalId],
            [
                'external_conversation_id' => $externalId,
                'channel' => $channel,
                'status' => 'active',
                'metadata' => $metadata,
                'last_message_at' => now(),
            ]
        );
    }

    /**
     * Cliente de la última conversación de ese usuario externo (BSUID), **incluidas las
     * archivadas**: el Reset del admin archiva la conversación, y si no se miran, el usuario que
     * vuelve a escribir parece nuevo y se le crea un cliente duplicado. Es la contraparte de
     * {@see findByExtUserId()}, que sí excluye archivadas porque busca la conversación viva.
     */
    public function findCustomerIdByExtUserId(string $extUserId): ?int
    {
        return Conversation::where('ext_user_id', $extUserId)
            ->whereNotNull('customer_id')
            ->latest('id')
            ->value('customer_id');
    }

    /**
     * Summary of linkCustomer
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
        $this->logConversation('Entrada a createOrUpdate con threadId: ', ['thread_id' => $threadId]);
        $existing = $this->findByThreadId($threadId);

        if ($existing && $existing->customer_id !== $customerId) {
            Log::warning('Thread ID conflict', [
                'external_conversation_id' => $threadId,
                'old_customer' => $existing->customer_id,
                'new_customer' => $customerId,
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
            $vehicleId => ['is_primary' => $isPrimary],
        ]);
    }

    public function findActiveByOpenAIUserId(string $threadId): ?Conversation
    {
        Log::info(__METHOD__.__LINE__.' Buscando conversación activa', ['external_conversation_id' => $threadId]);

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
        Conversation::where('ext_user_id', $openaiUserId)
            ->where('status', 'active')
            ->update(['last_message_at' => now()]);
    }

    /**
     * Filtra una query de Conversation por flags de salud (loops, stuck, tool_errors, abandoned, long).
     *
     * @param  Builder<Conversation>  $query
     * @param  array<int, string>  $flags
     * @return Builder<Conversation>
     */
    public function applyFlags(Builder $query, array $flags): Builder
    {
        foreach ($flags as $flag) {
            $query->whereJsonContains("flags->{$flag}", true);
        }

        return $query;
    }

    public function saveCoveragePreference(int $conversationId, int $vehicleId, string $preference): void
    {
        CoveragePreference::updateOrCreate(
            [
                'conversation_id' => $conversationId,
                'vehicle_id' => $vehicleId,
            ],
            [
                'preference' => $preference,
            ]
        );
    }
}
