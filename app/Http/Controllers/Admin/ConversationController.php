<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeConversationSemanticsJob;
use App\Jobs\SendWhatsAppMessage;
use App\Models\AgentExecutionLog;
use App\Models\AgentExecutionLogAnnotation;
use App\Models\AgentPrompt;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Repositories\ConversationRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ConversationController extends Controller
{
    /**
     * Lista todas las conversaciones ordenadas por actividad reciente.
     */
    public function index(Request $request, ConversationRepository $repo): Response
    {
        $allowedFlags = [
            // Tier 1 — reglas determinísticas
            'loops', 'stuck', 'tool_errors', 'abandoned', 'long',
            // Tier 2 — análisis semántico con IA (gateado)
            'user_frustrated', 'agent_confused', 'semantic_loop', 'context_loss', 'hallucination', 'incorrect_answer',
        ];
        $selectedFlags = array_values(array_intersect(
            (array) $request->input('flags', []),
            $allowedFlags,
        ));

        $sort = $request->input('sort');
        $direction = strtolower((string) $request->input('direction', 'asc'));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        $query = Conversation::with('customer')
            ->withCount('messages');

        if ($selectedFlags !== []) {
            $repo->applyFlags($query, $selectedFlags);
        }

        $allowedSorts = ['updated_at', 'created_at', 'status', 'channel', 'messages_count', 'customer_name'];
        if (in_array($sort, $allowedSorts, true)) {
            match ($sort) {
                'customer_name' => $query
                    ->leftJoin('customers', 'conversations.customer_id', '=', 'customers.id')
                    ->orderByRaw("LOWER(customers.name) {$direction}")
                    ->select('conversations.*'),
                default => $query->orderBy($sort, $direction),
            };
        } else {
            $query->orderByDesc('updated_at');
        }

        $conversations = $query->paginate(25)->withQueryString();

        $flagCounts = [];
        foreach ($allowedFlags as $flag) {
            $flagCounts[$flag] = Conversation::query()->whereJsonContains("flags->{$flag}", true)->count();
        }

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
                'flags' => is_array($c->flags) ? $c->flags : [],
                'messages_count' => $c->messages_count,
                'last_message_at' => $c->last_message_at->toIso8601String(),
                'created_at' => $c->created_at->toIso8601String(),
            ]),
            'filters' => [
                'flags' => $selectedFlags,
                'sort' => $sort,
                'direction' => $direction,
            ],
            'flag_counts' => $flagCounts,
        ]);
    }

    /**
     * Vista de auditoría de una conversación individual.
     *
     * Retorna los mensajes y los logs de ejecución del orquestador
     * para evaluar la calidad de las respuestas de los agentes.
     */
    public function show(Request $request, Conversation $conversation): Response
    {
        $conversation->load('customer');

        $messages = Message::where('conversation_id', $conversation->id)
            ->with('attachment')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (Message $m): array {
                $att = $m->attachment instanceof MessageAttachment ? $m->attachment : null;

                return [
                    'type' => $m->direction === 'inbound' ? 'message_inbound' : 'message_outbound',
                    'id' => $m->id,
                    'message_type' => $m->type,
                    'content' => $m->content,
                    'sender_name' => $m->sender_name,
                    'agent_name' => $m->agent_name,
                    'ai_provider' => $m->ai_provider,
                    'attachment' => $att ? [
                        'duration_seconds' => $att->duration_seconds,
                        'storage_url' => $att->storage_url,
                        'transcription' => $att->transcription,
                    ] : null,
                    'created_at' => $m->created_at->toIso8601String(),
                ];
            })
            ->all();

        $logs = AgentExecutionLog::where('conversation_id', $conversation->id)
            ->with(['annotations.user'])
            ->orderBy('created_at')
            ->get();

        $currentUserId = $request->user()->id;

        $executions = $logs
            ->map(fn (AgentExecutionLog $log): array => [
                'id' => $log->id,
                'agent_name' => $log->agent_name,
                'agent_prompt_id' => $log->agent_prompt_id,
                'step' => $log->step,
                'state_changes' => $log->state_changes,
                'chained' => $log->chained,
                'status' => $log->status,
                'error_message' => $log->error_message,
                'duration_ms' => $log->duration_ms,
                'input_tokens' => $log->input_tokens,
                'output_tokens' => $log->output_tokens,
                'inbound_message_ids' => $log->inbound_message_ids,
                'outbound_message_id' => $log->outbound_message_id,
                'tool_calls' => $log->tool_calls ?? [],
                'created_at' => $log->created_at->toIso8601String(),
                'annotations' => self::formatAnnotations($log->annotations->all(), $currentUserId),
            ])
            ->all();

        $customer = $conversation->customer instanceof Customer ? $conversation->customer : null;

        return Inertia::render('Admin/Conversations/Show', [
            'conversation' => [
                'id' => $conversation->id,
                'external_id' => $conversation->external_conversation_id,
                'ext_user_id' => $conversation->ext_user_id,
                'ext_username' => $conversation->ext_username,
                'customer' => $customer ? [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                ] : null,
                'channel' => $conversation->channel,
                'status' => $conversation->status,
                'ai_state' => $conversation->aiState(),
                'flags' => is_array($conversation->flags) ? $conversation->flags : [],
                'semantic_analysis' => is_array($conversation->semantic_analysis) ? $conversation->semantic_analysis : null,
                'last_semantic_analysis_at' => $conversation->last_semantic_analysis_at?->toIso8601String(),
                'created_at' => $conversation->created_at->toIso8601String(),
                'last_message_at' => $conversation->last_message_at->toIso8601String(),
                'ai_paused' => $conversation->isAiPaused(),
            ],
            'semantic_analysis_enabled' => (bool) config('ai.semantic_analysis.enabled'),
            'active_prompt_ids_by_agent' => self::activePromptIdsByAgent(),
            'messages' => $messages,
            'executions' => $executions,
            'stats' => [
                'total_invocations' => $logs->count(),
                'total_duration_ms' => (int) $logs->sum('duration_ms'),
                'total_input_tokens' => $logs->sum('input_tokens') ?: null,
                'total_output_tokens' => $logs->sum('output_tokens') ?: null,
            ],
        ]);
    }

    /**
     * Mapea el class_basename del agente (como se guarda en agent_execution_logs.agent_name)
     * al id del AgentPrompt activo correspondiente. Fallback para logs antiguos sin FK.
     *
     * @return array<string, int>
     */
    private static function activePromptIdsByAgent(): array
    {
        $map = [
            'CustomerIdentifierAgent' => 'customer_identifier',
            'VehicleIdentifierAgent' => 'vehicle_identifier',
            'CoveragePreferenceAgent' => 'coverage_preference',
            'QuoteAgent' => 'quote_reception',
            'CheckoutAgent' => 'checkout_closer',
        ];

        $result = [];
        foreach ($map as $agentName => $key) {
            $active = AgentPrompt::activeFor($key);
            if ($active instanceof AgentPrompt) {
                $result[$agentName] = $active->id;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, AgentExecutionLogAnnotation>  $annotations
     * @return array<int, array{id: int, verdict: bool, note: string|null, user_id: int, user_name: string|null, is_mine: bool, updated_at: string}>
     */
    private static function formatAnnotations(array $annotations, int $currentUserId): array
    {
        return array_values(array_map(fn (AgentExecutionLogAnnotation $a): array => [
            'id' => $a->id,
            'verdict' => $a->verdict,
            'note' => $a->note,
            'user_id' => $a->user_id,
            'user_name' => $a->user?->name,
            'is_mine' => $a->user_id === $currentUserId,
            'updated_at' => $a->updated_at->toIso8601String(),
        ], $annotations));
    }

    /**
     * Dispara el análisis semántico (Tier 2) manualmente bypassando throttle.
     */
    public function analyzeSemantics(Conversation $conversation): RedirectResponse
    {
        if (! (bool) config('ai.semantic_analysis.enabled')) {
            return redirect()->back()->with('error', 'El análisis semántico está deshabilitado. Activá AI_SEMANTIC_ANALYSIS_ENABLED.');
        }

        AnalyzeConversationSemanticsJob::dispatch($conversation->id, true);

        return redirect()->back()->with('success', 'Análisis semántico encolado. Refrescá en unos segundos.');
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

        DB::transaction(function () use ($conversation, $waId): void {
            // Desvincular la memoria del AI SDK: user_id es nullable en agent_conversations.
            // Ponerlo a null hace que latestConversationId() no los encuentre en el próximo flujo.
            // Los registros se conservan íntegros para auditoría de prompts/tools.
            DB::table('agent_conversations')
                ->where('user_id', $waId)
                ->update(['user_id' => null]);

            // Archivar la conversación desvinculando su external_id.
            // findOrCreateByExternalId(wa_id) no la encontrará → creará una conversación nueva.
            // Todos los mensajes, cotizaciones y preferencias quedan intactos para auditoría.
            $conversation->update([
                'status' => 'archived',
                'external_conversation_id' => "archived_{$conversation->id}",
            ]);
        });

        return redirect()->route('admin.conversations.index')
            ->with('success', "Conversación de {$waId} archivada. Todo el contexto se conserva para auditoría. El próximo mensaje iniciará el flujo desde cero.");
    }

    /**
     * Toma control humano de la conversación: la IA deja de responder a los
     * mensajes entrantes hasta que un admin la reanude.
     */
    public function pauseAi(Conversation $conversation): RedirectResponse
    {
        $conversation->setAiPaused(true);

        return back()->with('success', 'IA pausada. Los mensajes del cliente quedan para respuesta manual.');
    }

    /**
     * Devuelve el control a la IA. El próximo turno incluye un resumen de lo
     * intercambiado durante la pausa (ver ProcessConversationInbox::prependPauseTranscript).
     */
    public function resumeAi(Conversation $conversation): RedirectResponse
    {
        $conversation->setAiPaused(false);

        return back()->with('success', 'IA reactivada.');
    }

    /**
     * Envía un mensaje manual del asesor humano por el mismo pipeline outbound
     * que usan los agentes. Pensado para usarse con la IA pausada.
     */
    public function sendManualMessage(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate(['text' => 'required|string|max:4096']);

        // Derivación phone/bsuid — mismo patrón que NotifyClientQuoteReady.
        $bsuid = $conversation->ext_user_id;
        $phone = $conversation->external_conversation_id === $bsuid ? null : $conversation->external_conversation_id;

        SendWhatsAppMessage::dispatch(
            $phone,
            $bsuid,
            $validated['text'],
            config('services.whatsapp.phone_number_id'),
            $conversation->id,
            'human'
        )->onQueue('whatsapp-outbound');

        return back()->with('success', 'Mensaje enviado.');
    }
}
