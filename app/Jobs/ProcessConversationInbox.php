<?php

namespace App\Jobs;

use App\AI\InsuranceOrchestrator;
use App\Models\AgentExecutionLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Traits\DespachaRespuestaDelAgente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessConversationInbox implements ShouldQueue
{
    use DespachaRespuestaDelAgente;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly int $conversationId,
        private readonly ?string $waId,
        private readonly string $phoneNumberId,
    ) {
        // Usa la conexión con retry_after extendido (200s) para tolerar llamadas largas al LLM.
        $this->onConnection('database_ai');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("inbox:{$this->conversationId}"))
                ->releaseAfter(5)
                ->expireAfter(120),
        ];
    }

    public function handle(InsuranceOrchestrator $orchestrator): void
    {
        $messages = Message::where('conversation_id', $this->conversationId)
            ->where('direction', 'inbound')
            ->whereNull('processed_at')
            ->whereNotNull('content') // skip messages pending media transcription
            ->orderBy('created_at')
            ->get();

        if ($messages->isEmpty()) {
            Log::info('WhatsApp inbox: sin mensajes pendientes', [
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        $conversation = Conversation::findOrFail($this->conversationId);

        // Marcar como procesados ANTES de llamar al AI para que un reintento
        // del job no vuelva a llamar al AI con los mismos mensajes.
        Message::whereIn('id', $messages->pluck('id'))
            ->update(['processed_at' => now()]);

        if ($conversation->isAiPaused()) {
            // El humano responde desde el panel de administración; estos mensajes
            // ya quedaron marcados como processed para que al reanudar la IA no
            // se re-inyecten al LLM (van resumidos en el transcript de la pausa).
            Log::info('WhatsApp inbox: IA pausada, mensajes derivados al humano', [
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        $combinedBody = $this->prependCustomerContext(
            $conversation,
            $this->prependPauseTranscript($conversation, $messages->pluck('content')->implode("\n")),
        );

        $reply = $orchestrator->handle($combinedBody, $conversation);
        $inboundIds = $messages->pluck('id')->all();
        $lastLogId = end($reply['execution_log_ids']) ?: null;

        // Vincular los mensajes inbound que dispararon esta ejecución
        AgentExecutionLog::whereIn('id', $reply['execution_log_ids'])
            ->update(['inbound_message_ids' => json_encode($inboundIds)]);

        // Destinatario: el teléfono del webhook si llegó; si no, el BSUID de la conversación.
        $this->despacharRespuesta(
            new SendWhatsAppMessage($this->waId, $conversation->ext_user_id, $reply['text'], $this->phoneNumberId, $this->conversationId, $reply['agent'], $lastLogId, $reply['buttons'] ?? null),
            $reply['public_link'] ?? null,
            $this->waId,
            $conversation->ext_user_id,
            $this->phoneNumberId,
            $this->conversationId,
        );

        $contactName = $messages->first()?->sender_name;

        if ($contactName) {
            $conversation->refresh();

            if ($conversation->customer && ! $conversation->customer->name) {
                $conversation->customer->update(['name' => $contactName]);
            }
        }

        AnalyzeConversationHealthJob::dispatch($this->conversationId);

        // Tier 2: análisis semántico con IA. Gated por feature flag + throttle por turns.
        if ((bool) config('ai.semantic_analysis.enabled')) {
            $every = max(1, (int) config('ai.semantic_analysis.trigger_every_n_turns', 3));
            $total = (int) Message::where('conversation_id', $this->conversationId)->count();
            if ($total > 0 && $total % $every === 0) {
                AnalyzeConversationSemanticsJob::dispatch($this->conversationId);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('WhatsApp inbox: Job falló definitivamente', [
            'conversation_id' => $this->conversationId,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * En el PRIMER turno (hilo de memoria del agente todavía vacío), antepone el nombre del
     * cliente para que el saludo sea personal. Es una pista para el saludo, no un dato
     * confirmado — el nombre del perfil de WhatsApp puede ser fantasía (el prompt ya lo aclara).
     * Solo el primer turno para no repetir la línea en cada mensaje del hilo.
     */
    private function prependCustomerContext(Conversation $conversation, string $body): string
    {
        $name = $conversation->customer?->name;

        if (! $name) {
            return $body;
        }

        $hasMemory = DB::table('agent_conversations')->where('user_id', $conversation->id)->exists();

        if ($hasMemory) {
            return $body;
        }

        return "[Contexto: el cliente figura como \"{$name}\" en WhatsApp — usalo para el saludo, no como dato confirmado.]\n\n{$body}";
    }

    /**
     * Si la conversación acaba de salir de un takeover humano, antepone un
     * resumen de lo intercambiado durante la pausa para que la IA no pierda
     * el hilo. Consume los marcadores de la pausa (no se repite en turnos futuros).
     */
    private function prependPauseTranscript(Conversation $conversation, string $body): string
    {
        $pausedAt = data_get($conversation->metadata, 'ai_paused_at');
        $resumedAt = data_get($conversation->metadata, 'ai_resumed_at');

        if (! $pausedAt || ! $resumedAt) {
            return $body;
        }

        // Parsear a Carbon: el binding de whereBetween necesita el mismo formato
        // que usa la columna en DB, y las strings ISO8601 crudas no matchean.
        $transcript = Message::where('conversation_id', $conversation->id)
            ->whereBetween('created_at', [Carbon::parse($pausedAt), Carbon::parse($resumedAt)])
            ->orderBy('created_at')
            ->get(['direction', 'content', 'agent_name'])
            ->map(fn (Message $m): string => ($m->direction === 'inbound' ? 'Cliente' : 'Asesor').': '.$m->content)
            ->implode("\n");

        $meta = $conversation->metadata ?? [];
        unset($meta['ai_paused_at'], $meta['ai_resumed_at']);
        $conversation->update(['metadata' => $meta]);

        if ($transcript === '') {
            return $body;
        }

        return "[Contexto: un asesor humano atendió esta conversación. Intercambio durante la pausa:\n{$transcript}]\n\n{$body}";
    }
}
