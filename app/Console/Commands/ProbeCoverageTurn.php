<?php

namespace App\Console\Commands;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Agents\CoveragePreferenceAgent;
use App\AI\Probes\DeepSeekProbe;
use App\AI\Probes\ProbeStats;
use App\AI\Probes\TurnRequest;
use App\AI\Tools\CheckCoverageRuleTool;
use App\AI\Tools\CoveragePreferenceTool;
use App\AI\Tools\ProvideVehicleFactTool;
use App\AI\Tools\RevertStageTool;
use App\AI\Tools\SiniestroGuidanceTool;
use App\Models\Conversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use stdClass;
use Throwable;

/**
 * Repite N veces el turno de `CoveragePreferenceAgent` y muestra el TEXTO que escribe.
 *
 * Por qué existe: al sacar el aviso de espera hardcodeado (2026-08-22), el mensaje de este agente
 * quedó como el único que el cliente recibe antes de 100-130s de silencio. Si en algún turno no
 * menciona la espera, el cliente lee "Dale, te cotizo X e Y" y se queda mirando la pantalla. Eso hay
 * que medirlo, y con n=1 no alcanza.
 *
 * Por qué no sirve `ai:probe-presentation`: está cableada al turno del closer, y sobre todo **nunca
 * guarda el texto** — mide qué tool se llamó, que acá no es la pregunta.
 *
 * Cómo llega al texto sin ejecutar nada: el intercambio de la tool se **agrega ya resuelto** a los
 * mensajes ({@see TurnRequest::withToolExchange()}), así que la respuesta del modelo es directamente
 * lo que escribiría después de que la tool corriera. Cero efectos: no escribe en la base, no
 * despacha jobs, no manda WhatsApp.
 *
 * `--tool-output` es el punto interesante: permite probar redacciones alternativas del mensaje de la
 * tool sin desplegar nada.
 */
class ProbeCoverageTurn extends Command
{
    protected $signature = 'ai:probe-coverage-turn
                            {--runs=10 : Cuántas veces repetir el turno}
                            {--conversation=24 : ID de la conversación cuyo contexto se reevalúa}
                            {--tool-output= : Resultado de coverage_preference a inyectar (por defecto, el de producción)}
                            {--model= : Correr con otro modelo (por defecto, el tier del agente)}
                            {--json= : Volcar las corridas crudas a este archivo}';

    protected $description = 'Repite el turno de cobertura y muestra el texto que escribe el agente';

    /** Los mismos bloques que declara CoveragePreferenceAgent::$sharedBlocks. */
    private const SHARED_BLOCKS = ['shared_style', 'shared_grounding', 'shared_siniestro'];

    private const AGENTE = 'CoveragePreferenceAgent';

    private const TOOL = 'CoveragePreferenceTool';

    public function handle(DeepSeekProbe $probe): int
    {
        if (DeepSeekProbe::apiKey() === '') {
            $this->error('Falta DEEPSEEK_API_KEY.');

            return self::FAILURE;
        }

        $conversationId = (int) $this->option('conversation');
        $conversation = Conversation::find($conversationId);

        if ($conversation === null) {
            $this->error("No existe la conversación {$conversationId}.");

            return self::FAILURE;
        }

        try {
            $rows = TurnRequest::rowsUpToLastUser($conversationId, self::AGENTE);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $system = TurnRequest::system('coverage_preference', self::SHARED_BLOCKS);

        if (trim($system) === '') {
            $this->error('El prompt compuesto quedó vacío. ¿Hay una versión activa de coverage_preference?');

            return self::FAILURE;
        }

        $argumentos = $this->argumentosHistoricos($conversationId);
        $toolOutput = (string) ($this->option('tool-output') ?: $this->salidaDeProduccion($argumentos));

        $messages = TurnRequest::payload(
            TurnRequest::withToolExchange(TurnRequest::prismMessages($rows), self::TOOL, $argumentos, $toolOutput),
            $system,
        );

        $tools = TurnRequest::toolPayload($this->tools($conversation));
        $model = (string) ($this->option('model') ?: DeepSeekProbe::modelFor(CoveragePreferenceAgent::class));

        $this->cabecera($conversationId, $system, $rows, $model, $toolOutput);

        $runs = max(1, (int) $this->option('runs'));
        $resultados = [];

        for ($i = 1; $i <= $runs; $i++) {
            try {
                $r = $probe->send($model, $messages, $tools);
            } catch (Throwable $e) {
                $this->error("  corrida {$i}: ".$e->getMessage());

                return self::FAILURE;
            }

            /** @var list<string> $pedidas */
            $pedidas = collect($r['tool_calls'])
                ->map(fn (mixed $tc): string => (string) data_get($tc, 'function.name', '?'))
                ->all();

            $resultados[] = [
                'run' => $i,
                'ms' => $r['ms'],
                'prompt_tokens' => $r['prompt_tokens'],
                'completion_tokens' => $r['completion_tokens'],
                'finish_reason' => $r['finish_reason'],
                'tools' => $pedidas,
                'texto' => $r['content'],
            ];

            $this->line(sprintf(
                '  <options=bold>%2d</>  %6s ms · %s tok%s',
                $i,
                number_format($r['ms'], 0, ',', '.'),
                number_format($r['completion_tokens'], 0, ',', '.'),
                $pedidas === [] ? '' : '  <comment>[pidió '.implode(', ', $pedidas).']</comment>',
            ));
            $this->line('      '.str_replace("\n", "\n      ", trim($r['content'])));
            $this->newLine();
        }

        $this->volcarJson($resultados);
        $this->resumen($resultados);

        return self::SUCCESS;
    }

    /**
     * Los argumentos con los que el agente llamó la tool en la conversación real. Se usan los
     * históricos y no unos inventados para que el intercambio inyectado sea el que de verdad pasó.
     *
     * @return array<string, mixed>
     */
    private function argumentosHistoricos(int $conversationId): array
    {
        $fila = DB::table('agent_conversation_messages')
            ->where('conversation_id', TurnRequest::storeIdFor($conversationId))
            ->where('role', 'assistant')
            ->orderBy('id')
            ->get()
            ->first(function (stdClass $row): bool {
                foreach ((array) json_decode((string) $row->tool_calls, true) as $tc) {
                    if (($tc['name'] ?? null) === self::TOOL) {
                        return true;
                    }
                }

                return false;
            });

        if ($fila === null) {
            return ['coverage_code' => 'C', 'patente' => 'SIN-DATO'];
        }

        foreach ((array) json_decode((string) $fila->tool_calls, true) as $tc) {
            if (($tc['name'] ?? null) === self::TOOL) {
                /** @var array<string, mixed> $args */
                $args = (array) ($tc['arguments'] ?? []);

                return $args;
            }
        }

        return ['coverage_code' => 'C', 'patente' => 'SIN-DATO'];
    }

    /**
     * El `tool_output` tal como lo arma el adapter en la rama "todavía en marcha". El texto sale de
     * la constante compartida, así que no puede desincronizarse de producción.
     *
     * @param  array<string, mixed>  $argumentos
     */
    private function salidaDeProduccion(array $argumentos): string
    {
        $code = (string) ($argumentos['coverage_code'] ?? 'C');
        $patente = (string) ($argumentos['patente'] ?? 'SIN-DATO');

        return "Preferencia '{$code}' guardada para {$patente}. ".WhatsAppAdapter::PEDIDO_DE_AVISO;
    }

    /**
     * Las 5 tools que `InsuranceOrchestrator::resolveAgent()` le da al agente de cobertura.
     *
     * @return array<int, Tool>
     */
    private function tools(Conversation $conversation): array
    {
        /** @var WhatsAppAdapter $adapter */
        $adapter = app(WhatsAppAdapter::class);

        return [
            new CoveragePreferenceTool($adapter, $conversation),
            new CheckCoverageRuleTool,
            new ProvideVehicleFactTool($adapter, $conversation),
            new RevertStageTool($adapter, $conversation),
            new SiniestroGuidanceTool($conversation),
        ];
    }

    /**
     * @param  list<stdClass>  $rows
     */
    private function cabecera(int $conversationId, string $system, array $rows, string $model, string $toolOutput): void
    {
        $this->newLine();
        $this->line("  conversación  <options=bold>#{$conversationId}</>");
        $this->line("  modelo        <options=bold>{$model}</>");
        $this->line('  prompt        '.number_format(mb_strlen($system), 0, ',', '.').' caracteres (versión activa)');
        $this->line('  contexto      '.count($rows).' filas del store + el intercambio de la tool');
        $this->line('  tool_output   '.($this->option('tool-output') === null ? 'el de producción' : 'personalizado').
            ', '.number_format(mb_strlen($toolOutput), 0, ',', '.').' caracteres');
        $this->newLine();
        $this->line('  <options=bold>Textos generados</> — leerlos y contar cuántos avisan de la espera:');
        $this->newLine();
    }

    /**
     * @param  list<array<string, mixed>>  $resultados
     */
    private function resumen(array $resultados): void
    {
        /** @var list<int> $ms */
        $ms = collect($resultados)->pluck('ms')->all();
        /** @var list<int> $tok */
        $tok = collect($resultados)->pluck('completion_tokens')->all();

        $conTool = collect($resultados)->filter(fn (array $r): bool => $r['tools'] !== [])->count();

        $this->line('  corridas ............. '.count($resultados));
        $this->line('  latencia ............. '.ProbeStats::tramo($ms, 1000, ' s'));
        $this->line('  completion_tokens .... '.ProbeStats::tramo($tok));
        $this->line('  pidieron otra tool ... '.($conTool === 0 ? 'ninguna' : "{$conTool}"));
        $this->newLine();

        // A propósito no hay contador automático: un regex sobre "te las paso" / "te aviso" /
        // "en cuanto las tenga" sería frágil y daría una falsa sensación de rigor. Diez textos
        // cortos se leen.
        $this->line('  → Contá a ojo cuántos mencionan que le va a pasar las opciones.');
    }

    /**
     * @param  list<array<string, mixed>>  $resultados
     */
    private function volcarJson(array $resultados): void
    {
        $ruta = $this->option('json');

        if ($ruta === null) {
            return;
        }

        file_put_contents((string) $ruta, (string) json_encode($resultados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("  corridas volcadas a {$ruta}");
        $this->newLine();
    }
}
