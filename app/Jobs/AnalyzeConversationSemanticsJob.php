<?php

namespace App\Jobs;

use App\AI\Agents\ConversationAnalyzerAgent;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\AgentResponse;

class AnalyzeConversationSemanticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 120;

    /** @var array<int, string> */
    public const FLAG_KEYS = [
        'user_frustrated',
        'agent_confused',
        'semantic_loop',
        'context_loss',
        'hallucination',
        'incorrect_answer',
    ];

    public function __construct(
        public int $conversationId,
        public bool $force = false,
    ) {
        $this->onQueue('semantic-analysis');
    }

    public function handle(): void
    {
        if (! $this->force && ! (bool) config('ai.semantic_analysis.enabled')) {
            return;
        }

        $conversation = Conversation::find($this->conversationId);
        if (! $conversation instanceof Conversation) {
            return;
        }

        $messagesCount = (int) $conversation->messages()->count();

        if (! $this->force) {
            // Nada nuevo desde el último análisis.
            if ((int) $conversation->semantic_analysis_turn_count === $messagesCount) {
                return;
            }

            // Throttle por tiempo.
            $last = $conversation->last_semantic_analysis_at;
            $throttleMin = (int) config('ai.semantic_analysis.throttle_minutes', 5);
            if ($last !== null && $last->gt(now()->subMinutes($throttleMin))) {
                return;
            }
        }

        $windowTurns = (int) config('ai.semantic_analysis.window_turns', 6);
        $transcript = $this->buildTranscript($conversation, $windowTurns);

        if ($transcript === '') {
            return;
        }

        try {
            /** @var AgentResponse $response */
            $response = ConversationAnalyzerAgent::make()->prompt($transcript);
        } catch (\Throwable $e) {
            Log::warning('Semantic analysis: prompt falló', [
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $parsed = $this->parseJson($response->text);
        if ($parsed === null) {
            Log::warning('Semantic analysis: respuesta no parseable', [
                'conversation_id' => $this->conversationId,
                'raw' => mb_substr($response->text, 0, 500),
            ]);

            return;
        }

        $booleanFlags = [];
        foreach (self::FLAG_KEYS as $key) {
            $booleanFlags[$key] = (bool) ($parsed[$key] ?? false);
        }

        // Merge en conversations.flags preservando los de Tier 1.
        $existing = is_array($conversation->flags) ? $conversation->flags : [];

        $conversation->update([
            'flags' => array_merge($existing, $booleanFlags),
            'semantic_analysis' => [
                'flags' => $booleanFlags,
                'reasoning' => is_array($parsed['reasoning'] ?? null) ? $parsed['reasoning'] : [],
                'analyzed_at' => now()->toIso8601String(),
                'window_turns' => $windowTurns,
                'messages_analyzed' => $messagesCount,
            ],
            'last_semantic_analysis_at' => now(),
            'semantic_analysis_turn_count' => $messagesCount,
        ]);
    }

    private function buildTranscript(Conversation $conversation, int $windowTurns): string
    {
        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('content')
            ->orderByDesc('id')
            ->limit($windowTurns * 2)
            ->get()
            ->reverse()
            ->values();

        if ($messages->isEmpty()) {
            return '';
        }

        $lines = $messages->map(function (Message $m): string {
            $role = $m->direction === 'inbound' ? 'USUARIO' : 'AGENTE';

            return "[{$role}] {$m->content}";
        })->implode("\n");

        return "Fragmento de conversación a auditar (últimos turnos):\n\n{$lines}";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJson(string $text): ?array
    {
        $trimmed = trim($text);

        // Extraer bloque JSON si viene envuelto en ```json ... ```
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $trimmed, $m) === 1) {
            $trimmed = $m[1];
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }
}
