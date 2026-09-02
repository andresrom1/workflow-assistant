<?php

namespace App\AI;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Agents\CheckoutAgent;
use App\AI\Agents\CoveragePreferenceAgent;
use App\AI\Agents\CustomerIdentifierAgent;
use App\AI\Agents\QuoteAgent;
use App\AI\Agents\VehicleIdentifierAgent;
use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\CheckoutTool;
use App\AI\Tools\CoveragePreferenceTool;
use App\AI\Tools\DeclineDniTool;
use App\AI\Tools\GetQuoteTool;
use App\AI\Tools\IdentifyCustomerTool;
use App\AI\Tools\IdentifyVehicleTool;
use App\AI\Tools\PresentQuoteOptionsTool;
use App\AI\Tools\ProvideVehicleFactTool;
use App\AI\Tools\RevertStageTool;
use App\AI\Tools\SiniestroGuidanceTool;
use App\Jobs\ProcessConversationInbox;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\AgentExecutionLog;
use App\Models\AgentPrompt;
use App\Models\Conversation;
use App\Support\MemoriaDelAgente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Responses\AgentResponse;

class InsuranceOrchestrator
{
    /**
     * Mensajes con los que el orquestador abre un turno encadenado. No son mensajes del
     * cliente: entran a la memoria como el pedido que el agente tiene que atender.
     */
    private const DISPARADOR_COTIZACIONES_LISTAS = 'Cotizaciones listas';

    private const DISPARADOR_COBERTURA_PENDIENTE = 'El vehículo quedó registrado y la consulta a las compañías ya está corriendo. '
        .'Si el cliente ya dijo qué cobertura quiere en algún mensaje anterior, registrala ahora con coverage_preference '
        .'sin volver a preguntársela; si no la dijo, preguntásela.';

    /**
     * Cuánto vale un pendiente dejado por una tool. Lo consume el final del mismo turno,
     * o sea segundos; el techo real es el `$timeout` del job (400s). Diez minutos deja
     * margen de sobra y marca como basura cualquier cosa más vieja.
     */
    private const PENDIENTE_VIGENCIA_MINUTOS = 10;

    private const MARCA_ENCADENADO = '[NO ENTREGADO al cliente: el turno siguió con otro agente y salió ese mensaje en lugar de éste. El cliente NUNCA leyó esto — no lo des por dicho ni lo cites.]';

    public function __construct(private readonly WhatsAppAdapter $adapter) {}

    /**
     * Punto de entrada principal del orquestador.
     *
     * Lee el estado del flujo desde conversation.metadata.ai_state,
     * selecciona el sub-agente correspondiente al paso actual,
     * lo invoca con memoria persistente y retorna la respuesta de texto.
     *
     * El estado se actualiza dentro de cada Tool al ejecutarse exitosamente,
     * por lo que en el próximo mensaje el orquestador derivará al siguiente agente.
     *
     * Salvo que la transición deje la conversación sin nadie esperando un mensaje del
     * cliente: ahí el turno se encadena y corre un segundo agente antes de contestar.
     * Ver {@see self::cadenaAEncadenar()}.
     *
     * @return array{text: string, agent: string, execution_log_ids: int[], buttons: list<array{id: string, title: string}>|null, public_link: string|null}
     */
    public function handle(string $message, Conversation $conversation): array
    {
        // Si el cliente ya vinculado (materializado en la ingesta) tiene DNI, saltear el paso
        // de identificación. La captura de teléfono/nombre ocurre en la ingesta, no acá.
        $this->syncCustomerIdentifiedState($conversation);

        $stateBefore = $conversation->aiState();
        $step = $this->stepFromState($stateBefore);

        // Todos los agentes comparten el mismo hilo de conversación, identificado por
        // el id interno de la conversación (estable e inmutable — no depende del teléfono
        // ni del BSUID, que pueden cambiar o faltar). Esto permite que el contexto fluya
        // entre agentes. Ver docs: la memoria del SDK se keyea acá, no en el canal.
        $waUser = (object) ['id' => $conversation->id];
        $agent = $this->resolveAgent($stateBefore, $conversation);

        [$response, $durationMs] = $this->correrAgente($agent, $waUser, $message, $conversation, $stateBefore, $step);

        $conversation->refresh();
        $stateAfter = $conversation->aiState();

        $cadena = $this->cadenaAEncadenar($stateBefore, $stateAfter, $conversation);

        if ($cadena !== null) {
            $primerLog = $this->registrarTurno($agent, $conversation, $stateBefore, $stateAfter, $step, true, $response, $durationMs);

            // El cliente nunca va a leer lo que escribió este agente: sale el mensaje del
            // encadenado. Sin la marca, el modelo lo da por dicho y lo cita en el turno
            // siguiente ("como te preguntaba recién...").
            if ($cadena['marcar']) {
                MemoriaDelAgente::marcarNoEntregada($conversation, self::MARCA_ENCADENADO);
            }

            $segundoAgente = $this->resolveAgent($stateAfter, $conversation);
            $segundoStep = $this->stepFromState($stateAfter);

            // El par de líneas es el rastro del turno encadenado. Un proceso que muere en el
            // alarm del job no puede loguear su propia muerte —no hay excepción, no hay fila en
            // `failed_jobs`—, así que la señal es un "encadenando" sin su "encadenado listo" al
            // lado. Sin esto, el turno perdido del 2026-09-02 no dejó una sola línea.
            Log::info('Orquestador: encadenando turno', [
                'conversation_id' => $conversation->id,
                'de' => class_basename($agent),
                'a' => class_basename($segundoAgente),
            ]);

            [$segundaResponse, $segundaDuracion] = $this->correrAgente($segundoAgente, $waUser, $cadena['disparador'], $conversation, $stateAfter, $segundoStep);

            Log::info('Orquestador: encadenado listo', [
                'conversation_id' => $conversation->id,
                'a' => class_basename($segundoAgente),
                'duration_ms' => $segundaDuracion,
            ]);

            $conversation->refresh();
            $stateFinal = $conversation->aiState();

            $segundoLog = $this->registrarTurno($segundoAgente, $conversation, $stateAfter, $stateFinal, $segundoStep, false, $segundaResponse, $segundaDuracion);

            $pendienteEncadenado = $this->pullPending($conversation);

            return [
                'text' => $segundaResponse->text,
                'agent' => class_basename($segundoAgente),
                'execution_log_ids' => [$primerLog->id, $segundoLog->id],
                'buttons' => $pendienteEncadenado['buttons'],
                'public_link' => $pendienteEncadenado['public_link'],
            ];
        }

        $log = $this->registrarTurno($agent, $conversation, $stateBefore, $stateAfter, $step, false, $response, $durationMs);

        $pendiente = $this->pullPending($conversation);

        return [
            'text' => $response->text,
            'agent' => class_basename($agent),
            'execution_log_ids' => [$log->id],
            'buttons' => $pendiente['buttons'],
            'public_link' => $pendiente['public_link'],
        ];
    }

    /**
     * ¿Este turno tiene que seguir con otro agente antes de contestarle al cliente?
     *
     * Se encadena cuando la transición de estado deja la conversación **sin nadie esperando
     * un mensaje del cliente**: el texto del primer agente se descarta y sale el del segundo,
     * que es el dueño de la etapa nueva y tiene las tools de esa etapa.
     *
     * @param  array<string, bool>  $stateBefore
     * @param  array<string, bool>  $stateAfter
     * @return array{disparador: string, marcar: bool}|null
     */
    private function cadenaAEncadenar(array $stateBefore, array $stateAfter, Conversation $conversation): ?array
    {
        // Cotización presentada: QuoteAgent ya mostró las alternativas, sigue el cierre.
        // No marca la respuesta descartada — es como venía funcionando, y cambiarlo altera el
        // contexto de un camino que ya corre en producción.
        if (! $stateBefore['quote_ready'] && $stateAfter['quote_ready']) {
            return ['disparador' => self::DISPARADOR_COTIZACIONES_LISTAS, 'marcar' => false];
        }

        // Vehículo cotizable registrado: el turno cerraría prometiendo las opciones, sin
        // pregunta abierta, y la cobertura sigue sin registrar. Si el cliente no vuelve a
        // escribir —y no tiene por qué, le acabamos de decir que espere— nadie la registra
        // nunca, y NotifyClientQuoteReady se niega a presentar sin ella. Ver ROADMAP,
        // bitácora 2026-09-02.
        //
        // `vehicle_identified` sólo flipea en VehicleIdentifierAgent (el único con
        // identify_vehicle), y la quote sólo existe en la rama Quotable: NeedsFact y
        // NotQuotable cierran preguntando algo, así que no entran acá.
        if (! $stateBefore['vehicle_identified'] && $stateAfter['vehicle_identified']
            && ! $stateAfter['coverage_set']
            && $this->cotizacionEnCurso($conversation)) {
            return ['disparador' => self::DISPARADOR_COBERTURA_PENDIENTE, 'marcar' => true];
        }

        return null;
    }

    /**
     * ¿Quedó una cotización esperando en esta conversación?
     *
     * `pending` es la consulta en vuelo; `processed` y vigente cubre el caso raro de que las
     * compañías hayan contestado dentro del mismo turno. Mismo criterio que
     * {@see WhatsAppAdapter::cotizacionVigenteDe()}.
     */
    private function cotizacionEnCurso(Conversation $conversation): bool
    {
        if ($conversation->quotes()->where('status', 'pending')->exists()) {
            return true;
        }

        return $conversation->quotes()->where('status', 'processed')->vigente()->exists();
    }

    /**
     * Corre un agente cronometrado. Si explota, deja el log de error y propaga.
     *
     * @param  array<string, bool>  $stateBefore
     * @return array{0: AgentResponse, 1: int}
     */
    private function correrAgente(
        Agent&Conversational $agent,
        object $waUser,
        string $message,
        Conversation $conversation,
        array $stateBefore,
        int $step,
    ): array {
        $start = hrtime(true);

        try {
            /** @var AgentResponse $response */
            $response = $agent->continueLastConversation($waUser)->prompt($message);
        } catch (\Throwable $e) {
            AgentExecutionLog::create([
                'conversation_id' => $conversation->id,
                'agent_name' => class_basename($agent),
                'agent_prompt_id' => $this->resolveAgentPromptId($agent),
                'step' => $step,
                'state_before' => $stateBefore,
                'state_after' => $stateBefore,
                'state_changes' => [],
                'chained' => false,
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'duration_ms' => (int) round((hrtime(true) - $start) / 1e6),
                'inbound_message_ids' => null,
                'outbound_message_id' => null,
                'input_tokens' => null,
                'output_tokens' => null,
                'tool_calls' => null,
            ]);

            throw $e;
        }

        return [$response, (int) round((hrtime(true) - $start) / 1e6)];
    }

    /**
     * Deja el rastro de un turno exitoso. `$chained` marca que este turno siguió con otro
     * agente — {@see ProcessConversationInbox} lo usa para no descartar una
     * respuesta que ya arrastró dos turnos de memoria.
     *
     * @param  array<string, bool>  $stateBefore
     * @param  array<string, bool>  $stateAfter
     */
    private function registrarTurno(
        Agent&Conversational $agent,
        Conversation $conversation,
        array $stateBefore,
        array $stateAfter,
        int $step,
        bool $chained,
        AgentResponse $response,
        int $durationMs,
    ): AgentExecutionLog {
        $stateChanges = array_filter(
            $stateAfter,
            fn (bool $val, string $key): bool => $val !== ($stateBefore[$key] ?? false),
            ARRAY_FILTER_USE_BOTH
        );

        return AgentExecutionLog::create([
            'conversation_id' => $conversation->id,
            'agent_name' => class_basename($agent),
            'agent_prompt_id' => $this->resolveAgentPromptId($agent),
            'step' => $step,
            'state_before' => $stateBefore,
            'state_after' => $stateAfter,
            'state_changes' => $stateChanges,
            'chained' => $chained,
            'status' => 'success',
            'duration_ms' => $durationMs,
            'inbound_message_ids' => null,
            'outbound_message_id' => null,
            'input_tokens' => $this->extractInputTokens($response),
            'output_tokens' => $this->extractOutputTokens($response),
            'tool_calls' => $this->extractToolCalls($response),
        ]);
    }

    /**
     * Levanta y limpia lo que una tool (ej. PresentQuoteOptionsTool) haya dejado pendiente en
     * metadata durante este turno: los botones que acompañan al mensaje y el link a la vista
     * pública de la cotización, que sale en un mensaje aparte inmediatamente después.
     *
     * @return array{buttons: list<array{id: string, title: string}>|null, public_link: string|null}
     */
    private function pullPending(Conversation $conversation): array
    {
        $conversation->refresh();
        $meta = $conversation->metadata ?? [];

        $buttons = data_get($meta, 'pending_interactive.buttons');
        $publicLink = data_get($meta, 'pending_public_link');

        if ($buttons === null && $publicLink === null) {
            return ['buttons' => null, 'public_link' => null];
        }

        // Un pendiente viejo es basura de un turno que murió entre la tool y el despacho: los
        // botones de una presentación que el cliente nunca recibió, esperando para pegarse al
        // próximo mensaje que salga, sea cual sea. Se limpia igual que uno consumido. Pasó en
        // producción el 2026-09-02: ver ROADMAP.
        $sello = data_get($meta, 'pending_at');
        $vencido = $sello === null
            || Carbon::parse((string) $sello)->addMinutes(self::PENDIENTE_VIGENCIA_MINUTOS)->isPast();

        unset($meta['pending_interactive'], $meta['pending_public_link'], $meta['pending_at']);
        $conversation->update(['metadata' => $meta]);

        if ($vencido) {
            Log::warning('Orquestador: pendientes vencidos descartados', [
                'conversation_id' => $conversation->id,
                'pending_at' => $sello,
            ]);

            return ['buttons' => null, 'public_link' => null];
        }

        return ['buttons' => $buttons, 'public_link' => $publicLink];
    }

    /**
     * Gate de estado: si el cliente ya vinculado a la conversación tiene DNI (identidad de
     * dominio), marca el paso de identificación como resuelto para saltear el
     * CustomerIdentifierAgent (cliente recurrente ya identificado).
     *
     * La materialización del Customer y la captura de teléfono/nombre ocurren en la ingesta
     * ({@see ProcessWhatsAppMessage}), no acá. Este método NO crea ni identifica:
     * solo lee el DNI y prende el flag. Si no hay DNI, el paso queda abierto para que el
     * agente lo pida — cotizar con un DNI inventado rompe la emisión (ROADMAP 2026-07-19);
     * `DeclineDniTool` es la salida para el cliente que no lo quiere dar.
     */
    private function syncCustomerIdentifiedState(Conversation $conversation): void
    {
        if ($conversation->aiState()['customer_identified']) {
            return;
        }

        if ($conversation->customer?->dni) {
            $conversation->updateAiState(['customer_identified' => true]);
        }
    }

    /**
     * Mapea la clase del agente a la clave de su prompt activo y devuelve el id
     * de la versión que estaba activa al momento de esta ejecución. Si no hay
     * prompt en DB para esa clave (p.e. QuoteAgent que corre contra el .md), devuelve null.
     */
    private function resolveAgentPromptId(Agent $agent): ?int
    {
        $key = match (class_basename($agent)) {
            'CustomerIdentifierAgent' => 'customer_identifier',
            'VehicleIdentifierAgent' => 'vehicle_identifier',
            'CoveragePreferenceAgent' => 'coverage_preference',
            'QuoteAgent' => 'quote_reception',
            'CheckoutAgent' => 'checkout_closer',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        return AgentPrompt::activeFor($key)?->id;
    }

    /**
     * Mapea el ai_state al ordinal del paso activo (1–5).
     *
     * @param  array<string, bool>  $state
     */
    private function stepFromState(array $state): int
    {
        return match (true) {
            ! $state['customer_identified'] => 1,
            ! $state['vehicle_identified'] => 2,
            ! $state['coverage_set'] => 3,
            ! $state['quote_ready'] => 4,
            default => 5,
        };
    }

    /**
     * Extrae los tool calls del AgentResponse para persistirlos en el log.
     *
     * @return array<int, array{name: string, arguments: mixed}>
     */
    private function extractToolCalls(AgentResponse $response): array
    {
        return $response->toolCalls
            ->map(fn ($tc) => ['name' => $tc->name, 'arguments' => $tc->arguments])
            ->values()
            ->all();
    }

    /**
     * Extrae tokens de entrada del AgentResponse.
     * Retorna null si el SDK reportó 0 (valor por defecto — no trackeado).
     */
    private function extractInputTokens(AgentResponse $response): ?int
    {
        $tokens = $response->usage->promptTokens;

        return $tokens > 0 ? $tokens : null;
    }

    /**
     * Extrae tokens de salida del AgentResponse.
     * Retorna null si el SDK reportó 0 (valor por defecto — no trackeado).
     */
    private function extractOutputTokens(AgentResponse $response): ?int
    {
        $tokens = $response->usage->completionTokens;

        return $tokens > 0 ? $tokens : null;
    }

    /**
     * Selecciona el sub-agente activo según el estado actual del flujo.
     *
     * El orden respeta la secuencia del proceso de cotización:
     * cliente → vehículo → cobertura → cotización → checkout.
     */
    private function resolveAgent(array $state, Conversation $conversation): Agent&Conversational
    {
        $coverageTool = new CheckCoverageRuleTool;
        // Disponible desde cobertura en adelante — no en CustomerIdentifier ni
        // VehicleIdentifier, cuyas etapas no tienen una anterior a la que volver.
        $revertTool = new RevertStageTool($this->adapter, $conversation);
        // Disponible en las 5 etapas: un siniestro puede reportarse en cualquier momento.
        $siniestroTool = new SiniestroGuidanceTool($conversation);

        return match (true) {
            ! $state['customer_identified'] => new CustomerIdentifierAgent(
                new IdentifyCustomerTool($this->adapter, $conversation),
                $coverageTool,
                $siniestroTool,
                new DeclineDniTool($conversation),
            ),
            ! $state['vehicle_identified'] => new VehicleIdentifierAgent(
                new IdentifyVehicleTool($this->adapter, $conversation),
                $coverageTool,
                $siniestroTool,
            ),
            ! $state['coverage_set'] => new CoveragePreferenceAgent(
                new CoveragePreferenceTool($this->adapter, $conversation),
                $coverageTool,
                new ProvideVehicleFactTool($this->adapter, $conversation),
                $revertTool,
                $siniestroTool,
            ),
            ! $state['quote_ready'] => new QuoteAgent(
                new GetQuoteTool($this->adapter, $conversation),
                $coverageTool,
                $revertTool,
                $siniestroTool,
            ),
            default => new CheckoutAgent(
                new CheckoutTool($this->adapter, $conversation),
                $coverageTool,
                $revertTool,
                new PresentQuoteOptionsTool($conversation),
                $siniestroTool,
            ),
        };
    }
}
