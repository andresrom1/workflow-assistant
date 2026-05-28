<?php

namespace App\Jobs;

use App\Models\AgentExecutionLog;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class AnalyzeConversationHealthJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $conversationId) {}

    public function handle(): void
    {
        $conversation = Conversation::find($this->conversationId);
        if (! $conversation instanceof Conversation) {
            return;
        }

        $existing = is_array($conversation->flags) ? $conversation->flags : [];

        // Incrementamos el contador de turns en este step. Los resets ocurren en
        // Conversation::updateAiState() cuando una ai_state flag pasa false→true.
        $turnsInStep = ((int) $conversation->turns_in_current_step) + 1;

        $flags = [
            'loops' => self::detectLoops($conversation),
            'stuck' => $turnsInStep >= 5,
            'tool_errors' => self::detectToolErrors($conversation),
            'abandoned' => self::detectAbandoned($conversation),
            'long' => self::detectLong($conversation),
        ];

        $conversation->update([
            'flags' => array_merge($existing, $flags),
            'turns_in_current_step' => $turnsInStep,
            'last_health_analysis_at' => now(),
        ]);
    }

    private static function detectLoops(Conversation $conversation): bool
    {
        $recent = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->whereNotNull('content')
            ->latest('id')
            ->limit(10)
            ->pluck('content');

        $hashes = [];
        foreach ($recent as $content) {
            $normalized = self::normalize((string) $content);
            if ($normalized === '') {
                continue;
            }
            $hash = md5($normalized);
            if (isset($hashes[$hash])) {
                return true;
            }
            $hashes[$hash] = true;
        }

        return false;
    }

    private static function detectToolErrors(Conversation $conversation): bool
    {
        return AgentExecutionLog::query()
            ->where('conversation_id', $conversation->id)
            ->where(function ($q): void {
                $q->where('status', 'failed')
                    ->orWhereNotNull('error_message');
            })
            ->exists();
    }

    private static function detectAbandoned(Conversation $conversation): bool
    {
        $lastActivity = $conversation->last_message_at ?? $conversation->updated_at;
        if ($lastActivity === null) {
            return false;
        }

        $state = $conversation->aiState();
        $checkoutDone = (bool) ($state['checkout_done'] ?? false);

        return ! $checkoutDone && $lastActivity->lt(now()->subHours(24));
    }

    private static function detectLong(Conversation $conversation): bool
    {
        return $conversation->messages()->count() >= 20;
    }

    private static function normalize(string $text): string
    {
        $text = Str::lower(trim($text));
        $text = preg_replace('/[\p{P}\p{S}]+/u', '', $text) ?? '';
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        return trim($text);
    }
}
