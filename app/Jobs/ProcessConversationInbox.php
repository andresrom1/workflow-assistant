<?php

namespace App\Jobs;

use App\AI\InsuranceOrchestrator;
use App\Models\AgentExecutionLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsApp\WhatsAppOutboundService;
use App\Traits\DespachaRespuestaDelAgente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
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

    private const MARCA_NO_ENTREGADO = '[NO ENTREGADO al cliente: siguió escribiendo antes de que saliera. El cliente NUNCA leyó esto — no lo des por dicho ni lo cites.]';

    /**
     * Alto a propósito: la ventana deslizante se implementa con `release()`, y cada release
     * consume un intento. Con `tries = 3` el job se mataba solo esperando a un cliente que
     * seguía escribiendo. Los fallos reales los acota `maxExceptions`.
     */
    public int $tries = 10;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    /**
     * Por debajo del `retry_after` de la conexión `database_ai` (200s): si el job lo superara,
     * la cola lo re-reserva y quedan dos corriendo sobre la misma conversación.
     */
    public int $timeout = 180;

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
                // Cubre el peor turno medido (95s) más las intercepciones: si el lock expirara
                // antes, otro job entraría en paralelo sobre la misma conversación.
                ->expireAfter(300),
        ];
    }

    public function handle(InsuranceOrchestrator $orchestrator, WhatsAppOutboundService $waService): void
    {
        $messages = $this->mensajesPendientes();

        if ($messages->isEmpty()) {
            Log::info('WhatsApp inbox: sin mensajes pendientes', [
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        // Ventana de silencio deslizante: si el cliente todavía está escribiendo, este job se
        // devuelve a la cola sin gastar una llamada al LLM. Cada mensaje nuevo corre la ventana.
        $faltante = $this->segundosDeSilencioFaltantes($messages);

        if ($faltante > 0) {
            Log::info('WhatsApp inbox: el cliente sigue escribiendo, difiriendo el turno', [
                'conversation_id' => $this->conversationId,
                'segundos' => $faltante,
                'pendientes' => $messages->count(),
            ]);

            $this->release($faltante);

            return;
        }

        $conversation = Conversation::findOrFail($this->conversationId);

        // Marcar como procesados ANTES de llamar al AI para que un reintento
        // del job no vuelva a llamar al AI con los mismos mensajes.
        $this->marcarProcesados($messages);

        if ($conversation->isAiPaused()) {
            // El humano responde desde el panel de administración; estos mensajes
            // ya quedaron marcados como processed para que al reanudar la IA no
            // se re-inyecten al LLM (van resumidos en el transcript de la pausa).
            Log::info('WhatsApp inbox: IA pausada, mensajes derivados al humano', [
                'conversation_id' => $this->conversationId,
            ]);

            return;
        }

        // "Escribiendo…" desde acá y no desde el envío: en el envío la respuesta ya está lista
        // y el indicador se vería medio segundo. Marca de paso el mensaje como leído.
        $this->mostrarTypingIndicator($waService, $messages);

        $combinedBody = $this->prependCustomerContext(
            $conversation,
            $this->prependPauseTranscript($conversation, $messages->pluck('content')->implode("\n")),
        );

        $turno = $this->resolverTurno($orchestrator, $conversation, $combinedBody, $messages);

        $reply = $turno['reply'];
        $lastLogId = end($turno['log_ids']) ?: null;

        // Vincular los mensajes inbound que dispararon esta ejecución
        AgentExecutionLog::whereIn('id', $turno['log_ids'])
            ->update(['inbound_message_ids' => json_encode($turno['inbound_ids'])]);

        // Destinatario: el teléfono del webhook si llegó; si no, el BSUID de la conversación.
        $this->despacharRespuesta(
            new SendWhatsAppMessage($this->waId, $conversation->ext_user_id, $reply['text'], $this->phoneNumberId, $this->conversationId, $reply['agent'], $lastLogId, $turno['buttons']),
            $turno['public_link'],
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

    /**
     * Corre el turno y, si el cliente siguió escribiendo mientras el LLM pensaba, lo rehace
     * con lo nuevo incluido en vez de mandar una respuesta que ya quedó vieja.
     *
     * La ventana de intercepción es el tiempo de generación que se gasta igual, así que no
     * agrega latencia: solo cuesta un turno extra de LLM cuando efectivamente se usa.
     *
     * @param  Collection<int, Message>  $messages
     * @return array{reply: array{text: string, agent: string, execution_log_ids: list<int>, buttons: list<array{id: string, title: string}>|null, public_link: string|null}, log_ids: list<int>, inbound_ids: list<int>, buttons: list<array{id: string, title: string}>|null, public_link: string|null}
     */
    private function resolverTurno(
        InsuranceOrchestrator $orchestrator,
        Conversation $conversation,
        string $body,
        Collection $messages,
    ): array {
        $maxIntercepts = (int) config('whatsapp.inbox_max_intercepts', 2);
        $inboundIds = $messages->pluck('id')->all();
        $logIds = [];

        // Los botones y el link los deja una tool en metadata y `pullPending()` los CONSUME al
        // armar la respuesta. Si se descarta esa respuesta sin arrastrarlos, el redo ya no los
        // encuentra y el cliente se queda sin botones ni link a la cotización.
        $buttons = null;
        $publicLink = null;

        $intercepciones = 0;

        while (true) {
            $reply = $orchestrator->handle($body, $conversation);

            $logIds = array_merge($logIds, $reply['execution_log_ids']);
            $buttons = $reply['buttons'] ?? $buttons;
            $publicLink = $reply['public_link'] ?? $publicLink;

            $nuevos = $this->mensajesPendientes();

            if (! $this->debeInterceptar($nuevos, $intercepciones, $maxIntercepts, $reply['execution_log_ids'])) {
                return [
                    'reply' => $reply,
                    'log_ids' => $logIds,
                    'inbound_ids' => $inboundIds,
                    'buttons' => $buttons,
                    'public_link' => $publicLink,
                ];
            }

            Log::info('WhatsApp inbox: interceptando respuesta, el cliente siguió escribiendo', [
                'conversation_id' => $this->conversationId,
                'nuevos' => $nuevos->count(),
            ]);

            $this->marcarNoEntregado($conversation);
            $this->marcarProcesados($nuevos);

            $inboundIds = array_merge($inboundIds, $nuevos->pluck('id')->all());
            $body = $nuevos->pluck('content')->implode("\n");
            $intercepciones++;
        }
    }

    /**
     * Decide si la respuesta recién generada se descarta para rehacer el turno.
     *
     * @param  Collection<int, Message>  $nuevos
     * @param  list<int>  $logIds
     */
    private function debeInterceptar(Collection $nuevos, int $intercepciones, int $maxIntercepts, array $logIds): bool
    {
        if ($nuevos->isEmpty()) {
            return false;
        }

        if ($intercepciones >= $maxIntercepts) {
            Log::info('WhatsApp inbox: tope de intercepciones alcanzado, se envía igual', [
                'conversation_id' => $this->conversationId,
                'intercepciones' => $intercepciones,
            ]);

            return false;
        }

        if (! $this->esInterceptable($logIds)) {
            Log::info('WhatsApp inbox: turno no interceptable, se envía igual', [
                'conversation_id' => $this->conversationId,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Un turno se puede descartar salvo que haya disparado algo irreversible hacia afuera.
     *
     * El resto de las tools son find-or-create e idempotentes: dejarlas correr no rompe nada,
     * y sus efectos de dominio (Customer, Vehicle, ai_state) son datos válidos que el cliente
     * dio — no se deshacen. Lo que se descarta es el texto, no los hechos.
     *
     * @param  list<int>  $logIds
     */
    private function esInterceptable(array $logIds): bool
    {
        $irreversibles = ['coverage_preference', 'checkout'];

        $logs = AgentExecutionLog::whereIn('id', $logIds)->get(['chained', 'tool_calls']);

        foreach ($logs as $log) {
            // Turno encadenado (quote_ready flipeó): son dos turnos de agente y cuatro filas
            // de memoria, y coincide siempre con el disparo de la cotización.
            if ($log->chained) {
                return false;
            }

            foreach ($log->tool_calls ?? [] as $toolCall) {
                if (in_array($toolCall['name'], $irreversibles, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Marca en la memoria del agente la respuesta que se generó pero nunca salió.
     *
     * Se marca y no se borra: la fila del assistant carga también `tool_calls` y
     * `tool_results`, y el contexto del modelo se reconstruye desde ahí. Borrarla le sacaría
     * el registro de que la tool ya corrió y qué devolvió, y el redo tendería a re-ejecutarla.
     * La marca va en `content` porque `meta` no se reconstruye en el contexto.
     */
    private function marcarNoEntregado(Conversation $conversation): void
    {
        $fila = DB::table('agent_conversation_messages')
            ->where('user_id', $conversation->id)
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->first(['id', 'content']);

        if (! $fila) {
            return;
        }

        DB::table('agent_conversation_messages')
            ->where('id', $fila->id)
            ->update([
                'content' => self::MARCA_NO_ENTREGADO."\n".$fila->content,
            ]);
    }

    /**
     * Mensajes del cliente todavía sin procesar, en orden de llegada.
     *
     * @return Collection<int, Message>
     */
    private function mensajesPendientes(): Collection
    {
        return Message::where('conversation_id', $this->conversationId)
            ->where('direction', 'inbound')
            ->whereNull('processed_at')
            ->whereNotNull('content') // skip messages pending media transcription
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    private function marcarProcesados(Collection $messages): void
    {
        Message::whereIn('id', $messages->pluck('id'))->update(['processed_at' => now()]);
    }

    /**
     * Segundos que faltan para que se cumpla la ventana de silencio, o 0 si ya se puede
     * procesar. La ventana se mide contra el mensaje MÁS NUEVO: por eso desliza.
     *
     * El tope duro se mide contra el MÁS VIEJO, para que alguien que escribe sin parar no
     * difiera el turno para siempre.
     *
     * @param  Collection<int, Message>  $messages
     */
    private function segundosDeSilencioFaltantes(Collection $messages): int
    {
        $quiet = (int) config('whatsapp.inbox_quiet_seconds', 3);
        $maxWait = (int) config('whatsapp.inbox_max_wait_seconds', 15);

        $masViejo = $messages->first()?->created_at;

        if ($masViejo instanceof Carbon && $masViejo->diffInSeconds(now()) >= $maxWait) {
            return 0;
        }

        $masNuevo = $messages->last()?->created_at;

        if (! $masNuevo instanceof Carbon) {
            return 0;
        }

        $silencio = (int) $masNuevo->diffInSeconds(now());

        return max(0, $quiet - $silencio);
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    private function mostrarTypingIndicator(WhatsAppOutboundService $waService, Collection $messages): void
    {
        if (! (bool) config('whatsapp.typing_indicator_enabled', true)) {
            return;
        }

        $wamid = $messages->last()?->external_message_id;

        if (! $wamid) {
            return;
        }

        $waService->sendTypingIndicator($wamid, $this->phoneNumberId);
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
