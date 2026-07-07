<?php

namespace App\Services\Message;

use App\Enums\MessageType;
use App\Enums\Modality;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;
use Log;

class MessageModalityDecider
{
    /** Agents whose responses should never be sent as audio. */
    private const TEXT_ONLY_AGENTS = ['QuoteAgent', 'CheckoutAgent', 'human', 'followup'];

    /** Minimum word count to be eligible for audio. */
    private const MIN_WORDS = 15;

    /** Sliding window size (last N eligible outbound messages). */
    private const WINDOW_SIZE = 10;

    /** Minimum eligible messages before band rules apply (cold start). */
    private const COLD_START_THRESHOLD = 2;

    /** Target audio ratio within the eligible universe. */
    private const TARGET_RATIO = 0.35;

    /** Lower bound of the allowed audio band. */
    private const BAND_FLOOR = 0.30;

    /** Upper bound of the allowed audio band. */
    private const BAND_CEILING = 0.40;

    /** Base probability in the neutral zone. */
    private const BASE_PROBABILITY = 0.25;

    /** Aggressiveness of the error-correction term. */
    private const K = 1.5;

    public function __construct(
        private readonly ContentClassifier $classifier,
    ) {}

    /**
     * Decide the modality for an outbound message.
     *
     * @return array{modality: Modality, eligible: bool, reason: string, ratio: float|null, p: float|null, window_size: int|null}
     */
    public function decide(string $text, string $agentName, Conversation $conversation): array
    {
        // Layer 0: Mirror prerequisite — user must have sent audio first.
        if (! $this->userHasSentAudio($conversation)) {
            return $this->result(Modality::Text, false, 'no_user_audio');
        }

        // Layer 1: Hard gates — deterministic, O(1), no LLM cost.
        if (! $this->passesHardGates($text, $agentName)) {
            return $this->result(Modality::Text, false, 'hard_gate');
        }

        // Layer 2: LLM content classifier — only runs if hard gates pass.
        if ($this->classifier->classify($text) === 'informational') {
            return $this->result(Modality::Text, false, 'llm_informational');
        }

        // Message is eligible for audio — statistical layers now apply.

        // Layer 3: Cold start — first N eligible messages are always text.
        $window = $this->getEligibleWindow($conversation);
        if ($window->count() < self::COLD_START_THRESHOLD) {
            return $this->result(Modality::Text, true, 'cold_start', windowSize: $window->count());
        }

        $audioCount = $window->where('type', MessageType::Audio)->count();
        $eligibleCount = $window->count() + 1; // +1 for the current message

        // Layer 4: Hard band constraints.
        $ratioIfText = $audioCount / $eligibleCount;
        $ratioIfAudio = ($audioCount + 1) / $eligibleCount;

        // Floor has priority: if sending text would drop below 30%, force audio
        // even if that pushes above the ceiling.
        if ($ratioIfText < self::BAND_FLOOR) {
            return $this->result(Modality::Audio, true, 'band_floor', ratio: $ratioIfText, windowSize: $window->count());
        }

        if ($ratioIfAudio > self::BAND_CEILING) {
            return $this->result(Modality::Text, true, 'band_ceiling', ratio: $ratioIfAudio, windowSize: $window->count());
        }

        // Layer 5: Probabilistic sampling in the neutral zone (30%–40%).
        $currentRatio = $audioCount / max(1, $window->count());
        $error = self::TARGET_RATIO - $currentRatio;
        $pAudio = self::BASE_PROBABILITY + self::K * $error;

        // Anti-streak: penalize consecutive audio, boost after consecutive text.
        $pAudio = $this->applyAntiStreak($pAudio, $window);
        $pAudio = (float) max(0.0, min(1.0, $pAudio));

        $modality = (random_int(0, PHP_INT_MAX) / PHP_INT_MAX) < $pAudio
            ? Modality::Audio
            : Modality::Text;

        return $this->result($modality, true, 'probabilistic', ratio: $currentRatio, p: $pAudio, windowSize: $window->count());
    }

    private function userHasSentAudio(Conversation $conversation): bool
    {
        return Message::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->where('type', MessageType::Audio)
            ->exists();
    }

    private function passesHardGates(string $text, string $agentName): bool
    {
        if (in_array($agentName, self::TEXT_ONLY_AGENTS, true)) {
            Log::info('Hard gate: agent is text-only', ['agentName' => $agentName]);

            return false;
        }

        if (str_word_count($text) < self::MIN_WORDS) {
            Log::info('Hard gate: message has too few words', ['wordCount' => str_word_count($text)]);

            return false;
        }

        // Contains a URL. URLs are terrible for text-to-speech.
        if (preg_match('/https?:\/\/\S+/i', $text)) {
            Log::info('Hard gate: message contains a URL', ['textPreview' => mb_substr($text, 0, 100)]);

            return false;
        }

        // Contains a monetary amount ($, ARS, USD followed by digits).
        // This perfectly aligns with the prompt's "Rule of precedence".
        if (preg_match('/(\$|ARS|USD)\s*[\d.,]+/i', $text)) {
            Log::info('Hard gate: message contains a monetary amount', ['textPreview' => mb_substr($text, 0, 100)]);

            return false;
        }

        // Contains list structure (lines starting with -, *, or 1.).
        // if (preg_match('/^[\s]*[-*]|\d+\.\s/m', $text)) {
        //     return false;
        // }
        // if (preg_match('/^[\s]*-|\d+\.\s/m', $text)) {
        //     return false;
        // }

        return true;
    }

    /**
     * Returns the last N eligible outbound messages (not "last N filtered").
     */
    private function getEligibleWindow(Conversation $conversation): Collection
    {
        return Message::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('audio_eligible', true)
            ->orderByDesc('created_at')
            ->limit(self::WINDOW_SIZE)
            ->get(['type', 'created_at']);
    }

    /**
     * Adjust probability based on recent streaks (bidirectional anti-streak).
     */
    private function applyAntiStreak(float $pAudio, Collection $window): float
    {
        $last = $window->first(); // most recent (DESC order)

        if ($last?->type === MessageType::Audio) {
            // Penalize after an audio message.
            return $pAudio * 0.6;
        }

        $consecutiveText = 0;
        foreach ($window as $msg) {
            if ($msg->type !== MessageType::Text) {
                break;
            }
            $consecutiveText++;
        }

        if ($consecutiveText >= 2) {
            // Boost after 2+ consecutive text messages.
            return $pAudio * 1.3;
        }

        return $pAudio;
    }

    /**
     * @return array{modality: Modality, eligible: bool, reason: string, ratio: float|null, p: float|null, window_size: int|null}
     */
    private function result(
        Modality $modality,
        bool $eligible,
        string $reason,
        ?float $ratio = null,
        ?float $p = null,
        ?int $windowSize = null,
    ): array {
        return [
            'modality' => $modality,
            'eligible' => $eligible,
            'reason' => $reason,
            'ratio' => $ratio,
            'p' => $p,
            'window_size' => $windowSize,
        ];
    }
}
