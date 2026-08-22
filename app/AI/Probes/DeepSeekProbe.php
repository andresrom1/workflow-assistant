<?php

namespace App\AI\Probes;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Attributes\UseSmartestModel;
use ReflectionClass;
use RuntimeException;

/**
 * Una llamada cruda a DeepSeek, cronometrada, sin pasar por Prism ni por `laravel/ai`.
 *
 * Las sondas necesitan inspeccionar lo que el modelo DECIDE sin ejecutar nada, y el SDK no lo
 * permite: `DeepSeek\Handlers\Text::handleToolCalls()` llama a `callTools()` ANTES de chequear
 * `shouldContinue()`, así que ni con `maxSteps = 1` se evitan los efectos. Pegándole directo, la
 * sonda lee `tool_calls` y se queda ahí: no escribe en la base, no despacha jobs, no manda WhatsApp.
 * Es seguro por construcción y no por convención.
 */
class DeepSeekProbe
{
    /**
     * Manda una request y devuelve lo que hace falta para juzgarla.
     *
     * `$extra` es para parámetros propios de una sonda puntual (`max_tokens`, `temperature`); por
     * defecto no se manda ninguno, que es como los manda producción.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<array-key, mixed>  $tools
     * @param  array<string, mixed>  $extra
     * @return array{ms: int, prompt_tokens: int, completion_tokens: int, cache_hit_tokens: int, cache_miss_tokens: int, finish_reason: string, content: string, tool_calls: list<array<string, mixed>>}
     *
     * @throws RuntimeException
     */
    public function send(string $model, array $messages, array $tools = [], array $extra = []): array
    {
        // La misma URL que usa el provider de Prism, para medir contra el endpoint real.
        $url = rtrim((string) config('prism.providers.deepseek.url', 'https://api.deepseek.com/v1'), '/');

        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
        ], $extra);

        if ($tools !== []) {
            // `auto` está hardcodeado en el SDK (AddsToolsToPrismRequests) — mandar otra cosa
            // mediría un comportamiento que en producción no existe.
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $arranque = hrtime(true);

        $response = Http::withToken(self::apiKey())
            ->timeout(400)
            ->post("{$url}/chat/completions", $payload);

        $ms = (int) round((hrtime(true) - $arranque) / 1e6);

        if ($response->failed()) {
            throw new RuntimeException("HTTP {$response->status()} — ".$response->body());
        }

        /** @var list<array<string, mixed>> $toolCalls */
        $toolCalls = (array) $response->json('choices.0.message.tool_calls', []);

        return [
            'ms' => $ms,
            'prompt_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'completion_tokens' => (int) $response->json('usage.completion_tokens', 0),
            // Prism descarta estos dos: su handler de DeepSeek solo lee prompt y completion, así
            // que `Usage::cacheReadInputTokens` da 0 siempre aunque la caché funcione.
            'cache_hit_tokens' => (int) $response->json('usage.prompt_cache_hit_tokens', 0),
            'cache_miss_tokens' => (int) $response->json('usage.prompt_cache_miss_tokens', 0),
            'finish_reason' => (string) $response->json('choices.0.finish_reason', '?'),
            'content' => (string) $response->json('choices.0.message.content', ''),
            'tool_calls' => $toolCalls,
        ];
    }

    public static function apiKey(): string
    {
        return (string) config('ai.providers.deepseek.key');
    }

    /**
     * El modelo de un agente, sacado de su atributo de tier y no de un mapa aparte: `CheckoutAgent`
     * declara `#[UseSmartestModel]` y `CoveragePreferenceAgent` `#[UseCheapestModel]`, y medir con
     * el equivocado daría un número que no es el del turno real.
     *
     * @param  class-string  $agentClass
     */
    public static function modelFor(string $agentClass): string
    {
        /** @var array<string, string> $models */
        $models = (array) config('ai.providers.deepseek.models.text');

        $reflection = new ReflectionClass($agentClass);

        $tier = match (true) {
            $reflection->getAttributes(UseSmartestModel::class) !== [] => 'smartest',
            $reflection->getAttributes(UseCheapestModel::class) !== [] => 'cheapest',
            default => 'default',
        };

        return $models[$tier] ?? $models['default'] ?? 'deepseek-v4-flash';
    }
}
