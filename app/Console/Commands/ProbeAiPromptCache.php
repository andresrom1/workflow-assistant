<?php

namespace App\Console\Commands;

use App\AI\Agents\CheckoutAgent;
use App\AI\Agents\CoveragePreferenceAgent;
use App\AI\Agents\CustomerIdentifierAgent;
use App\AI\Agents\QuoteAgent;
use App\AI\Agents\VehicleIdentifierAgent;
use App\Models\AgentPrompt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Attributes\UseSmartestModel;
use ReflectionClass;

/**
 * Mide si el prompt de sistema pega en la caché de prefijos de DeepSeek, y cuánto ahorra.
 *
 * Por qué existe: al deduplicar el payload de `get_quote`, el prompt pasó a ser el ítem más grande
 * del turno de CheckoutAgent (~13.800 de 30.112 tokens medidos). Antes de recortar seis prompts hay
 * que saber si esos tokens cuestan TIEMPO o solo dinero — DeepSeek cachea prefijos automáticamente.
 *
 * Por qué no usa el SDK: `Laravel\Ai\Responses\Data\Usage` tiene los campos de caché, pero el
 * handler de DeepSeek de Prism nunca los llena (solo lee `prompt_tokens` y `completion_tokens`), así
 * que leerlos por ahí daría 0 siempre — y parchear `vendor` se pierde en el próximo composer update.
 * Esta sonda le pega a la API directo con Http, sin pasar por Prism ni por laravel/ai.
 */
class ProbeAiPromptCache extends Command
{
    protected $signature = 'ai:probe-cache
                            {agent=checkout_closer : agent_key a medir}
                            {--file= : Medir el contenido de este archivo en vez del prompt de la base}';

    protected $description = 'Mide el ahorro de la caché de prefijos de DeepSeek sobre el prompt de un agente';

    /** Clase de agente por agent_key, para leer sus bloques compartidos y su tier de modelo. */
    private const AGENT_CLASSES = [
        'customer_identifier' => CustomerIdentifierAgent::class,
        'vehicle_identifier' => VehicleIdentifierAgent::class,
        'coverage_preference' => CoveragePreferenceAgent::class,
        'quote_reception' => QuoteAgent::class,
        'checkout_closer' => CheckoutAgent::class,
    ];

    /** Por debajo de esto, el prefill que evita la caché no mueve la aguja de la UX. */
    private const UMBRAL_MS = 500;

    public function handle(): int
    {
        $key = (string) $this->argument('agent');
        $apiKey = (string) config('ai.providers.deepseek.key');

        if ($apiKey === '') {
            $this->error('Falta DEEPSEEK_API_KEY.');

            return self::FAILURE;
        }

        $prompt = $this->resolvePrompt($key);

        if (trim($prompt) === '') {
            $this->error("Sin contenido para {$key}. ¿La base tiene una versión activa?");

            return self::FAILURE;
        }

        $model = $this->resolveModel($key);

        $this->newLine();
        $this->line("  agente  <options=bold>{$key}</>");
        $this->line("  modelo  {$model}");
        $this->line('  prompt  '.number_format(mb_strlen($prompt), 0, ',', '.').' caracteres');
        $this->line('  origen  '.($this->option('file') ?? 'agent_prompts, compuesto con sus bloques compartidos'));
        $this->newLine();

        // El ruido va AL PRINCIPIO: la caché es por prefijo, así que un sufijo distinto no
        // invalidaría nada y la primera llamada pegaría hit igual, falseando el delta.
        $ruido = '['.Str::random(48)."]\n\n";

        $miss = $this->medir($apiKey, $model, $ruido.$prompt, 'miss forzado');
        $hit = $this->medir($apiKey, $model, $ruido.$prompt, 'repetición');
        $real = $this->medir($apiKey, $model, $prompt, 'prompt tal cual');

        if ($miss === null || $hit === null || $real === null) {
            return self::FAILURE;
        }

        $this->newLine();

        return $this->interpretar($miss, $hit, $real);
    }

    /**
     * @param  array{tokens: int, hit: int, miss: int, ms: int}  $miss
     * @param  array{tokens: int, hit: int, miss: int, ms: int}  $hit
     * @param  array{tokens: int, hit: int, miss: int, ms: int}  $real
     */
    private function interpretar(array $miss, array $hit, array $real): int
    {
        if ($miss['hit'] > 0) {
            $this->warn('  La llamada de miss forzado pegó caché igual: el delta no es confiable.');
            $this->warn('  Volvé a correr el comando.');

            return self::FAILURE;
        }

        if ($hit['hit'] === 0) {
            $this->warn('  El prefijo NO se cacheó ni repitiendo la misma llamada.');
            $this->newLine();
            $this->line('  → Recortar los prompts baja tokens Y tiempo. Es prioridad.');

            return self::SUCCESS;
        }

        $delta = $miss['ms'] - $hit['ms'];
        $pct = $miss['ms'] > 0 ? (int) round($delta * 100 / $miss['ms']) : 0;

        $this->info("  El prefijo cachea: {$delta} ms de diferencia sobre el MISMO prompt ({$pct} %).");

        $this->line($real['hit'] > 0
            ? '  Y el prompt tal cual ya venía caliente: el tráfico de producción lo mantiene vivo.'
            : '  Pero el prompt tal cual salió frío: entre conversaciones el prefijo se pierde.');

        $this->newLine();

        $this->line($delta < self::UMBRAL_MS
            ? '  → El tamaño del prompt casi no cuesta latencia. Recortarlo es ahorro de plata.'
            : '  → El tamaño del prompt sí cuesta latencia cada vez que no cachea.');

        return self::SUCCESS;
    }

    /**
     * Una llamada con `max_tokens = 1`: la respuesta es despreciable, así que el tiempo de pared es
     * esencialmente prefill — que es justo lo que la caché evita.
     *
     * @return array{tokens: int, hit: int, miss: int, ms: int}|null
     */
    private function medir(string $apiKey, string $model, string $system, string $etiqueta): ?array
    {
        // La misma URL que usa el provider de Prism, para medir contra el endpoint real.
        $url = rtrim((string) config('prism.providers.deepseek.url', 'https://api.deepseek.com/v1'), '/');

        $arranque = hrtime(true);

        $response = Http::withToken($apiKey)
            ->timeout(120)
            ->post("{$url}/chat/completions", [
                'model' => $model,
                'max_tokens' => 1,
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => 'ok'],
                ],
            ]);

        $ms = (int) round((hrtime(true) - $arranque) / 1e6);

        if ($response->failed()) {
            $this->error("  {$etiqueta}: HTTP {$response->status()} — ".$response->body());

            return null;
        }

        /** @var array<string, mixed> $usage */
        $usage = (array) $response->json('usage', []);

        $fila = [
            'tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'hit' => (int) ($usage['prompt_cache_hit_tokens'] ?? 0),
            'miss' => (int) ($usage['prompt_cache_miss_tokens'] ?? 0),
            'ms' => $ms,
        ];

        $this->line(sprintf(
            '  %-16s %9s tok   hit %9s / miss %9s   %7s ms',
            $etiqueta,
            number_format($fila['tokens'], 0, ',', '.'),
            number_format($fila['hit'], 0, ',', '.'),
            number_format($fila['miss'], 0, ',', '.'),
            number_format($fila['ms'], 0, ',', '.'),
        ));

        return $fila;
    }

    /** El prompt tal como lo compone el runtime, o el archivo que pidan medir. */
    private function resolvePrompt(string $key): string
    {
        $file = $this->option('file');

        if ($file === null) {
            return AgentPrompt::compose($key, $this->sharedBlocks($key));
        }

        return is_readable((string) $file) ? (string) file_get_contents((string) $file) : '';
    }

    /**
     * Bloques compartidos leídos por reflexión de la clase del agente, no hardcodeados: si mañana un
     * agente suma o saca uno, la sonda lo sigue sin que nadie se acuerde de tocarla.
     *
     * @return list<string>
     */
    private function sharedBlocks(string $key): array
    {
        // El experto de coberturas no es un Agent del orquestador: arma su lista en la tool.
        if ($key === 'coverage_check') {
            return ['shared_style', 'shared_grounding'];
        }

        $class = self::AGENT_CLASSES[$key] ?? null;

        if ($class === null) {
            return [];
        }

        /** @var list<string> $blocks */
        $blocks = (new ReflectionClass($class))->getDefaultProperties()['sharedBlocks'] ?? [];

        return $blocks;
    }

    /**
     * El tier sale de los atributos de la clase: CheckoutAgent corre en el modelo `smartest` y
     * QuoteAgent en el `cheapest`, y el prefill no cuesta lo mismo en los dos.
     */
    private function resolveModel(string $key): string
    {
        /** @var array<string, string> $models */
        $models = (array) config('ai.providers.deepseek.models.text');

        $fallback = $models['default'] ?? 'deepseek-v4-flash';
        $class = self::AGENT_CLASSES[$key] ?? null;

        if ($class === null) {
            return $fallback;
        }

        $reflection = new ReflectionClass($class);

        $tier = match (true) {
            $reflection->getAttributes(UseSmartestModel::class) !== [] => 'smartest',
            $reflection->getAttributes(UseCheapestModel::class) !== [] => 'cheapest',
            default => 'default',
        };

        return $models[$tier] ?? $fallback;
    }
}
