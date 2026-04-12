<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentExecutionLog;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageAttachment;
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
                'last_message_at' => $c->last_message_at->toIso8601String(),
                'created_at' => $c->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Vista de auditoría de una conversación individual.
     *
     * Retorna los mensajes y los logs de ejecución del orquestador
     * para evaluar la calidad de las respuestas de los agentes.
     */
    public function show(Conversation $conversation): Response
    {
        $conversation->load('customer');

        $messages = Message::where('conversation_id', $conversation->id)
            ->with('attachment')
            ->orderBy('created_at')
            ->get()
            ->map(function (Message $m): array {
                $att = $m->attachment instanceof MessageAttachment ? $m->attachment : null;

                return [
                    'type'         => $m->direction === 'inbound' ? 'message_inbound' : 'message_outbound',
                    'id'           => $m->id,
                    'message_type' => $m->type,
                    'content'      => $m->content,
                    'sender_name'  => $m->sender_name,
                    'agent_name'   => $m->agent_name,
                    'attachment'   => $att ? [
                        'duration_seconds' => $att->duration_seconds,
                        'storage_url'      => $att->storage_url,
                        'transcription'    => $att->transcription,
                    ] : null,
                    'created_at'   => $m->created_at->toIso8601String(),
                ];
            })
            ->all();

        $logs = AgentExecutionLog::where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get();

        $executions = $logs
            ->map(fn (AgentExecutionLog $log): array => [
                'id'                  => $log->id,
                'agent_name'          => $log->agent_name,
                'step'                => $log->step,
                'state_changes'       => $log->state_changes,
                'chained'             => $log->chained,
                'status'              => $log->status,
                'error_message'       => $log->error_message,
                'duration_ms'         => $log->duration_ms,
                'input_tokens'        => $log->input_tokens,
                'output_tokens'       => $log->output_tokens,
                'inbound_message_ids' => $log->inbound_message_ids,
                'outbound_message_id' => $log->outbound_message_id,
                'created_at'          => $log->created_at->toIso8601String(),
            ])
            ->all();

        $customer = $conversation->customer instanceof Customer ? $conversation->customer : null;

        return Inertia::render('Admin/Conversations/Show', [
            'conversation' => [
                'id'              => $conversation->id,
                'external_id'     => $conversation->external_conversation_id,
                'ext_user_id'     => $conversation->ext_user_id,
                'ext_username'    => $conversation->ext_username,
                'customer'        => $customer ? [
                    'id'    => $customer->id,
                    'name'  => $customer->name,
                    'phone' => $customer->phone,
                ] : null,
                'channel'         => $conversation->channel,
                'status'          => $conversation->status,
                'ai_state'        => $conversation->aiState(),
                'created_at'      => $conversation->created_at->toIso8601String(),
                'last_message_at' => $conversation->last_message_at->toIso8601String(),
            ],
            'messages'   => $messages,
            'executions' => $executions,
            'stats'      => [
                'total_invocations'   => $logs->count(),
                'total_duration_ms'   => (int) $logs->sum('duration_ms'),
                'total_input_tokens'  => $logs->sum('input_tokens') ?: null,
                'total_output_tokens' => $logs->sum('output_tokens') ?: null,
            ],
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
