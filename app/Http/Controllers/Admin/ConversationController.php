<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    /**
     * Lista todas las conversaciones ordenadas por actividad reciente.
     */
    public function index(): Response
    {
        $conversations = Conversation::with('customer')
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->paginate(25);

        return Inertia::render('Admin/Conversations/Index', [
            'conversations' => $conversations->through(fn (Conversation $c): array => [
                'id' => $c->id,
                'external_id' => $c->external_conversation_id,
                'ext_user_id' => $c->ext_user_id,
                'ext_username' => $c->ext_username,
                'customer' => $c->customer ? [
                    'id' => $c->customer->id,
                    'name' => $c->customer->name,
                    'phone' => $c->customer->phone,
                ] : null,
                'channel' => $c->channel,
                'status' => $c->status,
                'ai_state' => $c->aiState(),
                'messages_count' => $c->messages_count,
                'last_message_at' => $c->last_message_at?->toIso8601String(),
                'created_at' => $c->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Resetea completamente una conversación (dev tool).
     *
     * Borra en cascada:
     *   1. Mensajes, cotizaciones, preferencias de cobertura, vehículos vinculados
     *   2. Memoria del AI SDK (agent_conversation_messages → agent_conversations)
     *   3. La conversación principal (force delete, bypasa soft delete)
     */
    public function reset(Conversation $conversation): RedirectResponse
    {
        // external_conversation_id es el wa_id — clave usada por el AI SDK en agent_conversations.user_id
        $waId = $conversation->external_conversation_id;

        // 1. Cascada dentro de la app — DB::table() para borrado real (bypasa SoftDeletes)
        DB::table('messages')->where('conversation_id', $conversation->id)->delete();
        DB::table('quotes')->where('conversation_id', $conversation->id)->delete();
        DB::table('coverage_preferences')->where('conversation_id', $conversation->id)->delete();
        DB::table('conversation_vehicle')->where('conversation_id', $conversation->id)->delete();

        // 2. Memoria del AI SDK — keyed por external_conversation_id (= wa_id = agent_conversations.user_id)
        $agentConvIds = DB::table('agent_conversations')
            ->where('user_id', $waId)
            ->pluck('id');

        if ($agentConvIds->isNotEmpty()) {
            DB::table('agent_conversation_messages')
                ->whereIn('conversation_id', $agentConvIds)
                ->delete();

            DB::table('agent_conversations')
                ->whereIn('id', $agentConvIds)
                ->delete();
        }

        // 3. Conversación principal (force delete para limpiar el soft delete también)
        $conversation->forceDelete();

        return redirect()->route('admin.conversations.index')
            ->with('success', "Conversación de {$waId} reseteada. El próximo mensaje inicia el flujo desde cero.");
    }
}
