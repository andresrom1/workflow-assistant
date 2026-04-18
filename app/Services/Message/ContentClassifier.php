<?php

namespace App\Services\Message;

use Illuminate\Support\Facades\Log;

use function Laravel\Ai\agent;

class ContentClassifier
{
    private const INSTRUCTIONS_1 = <<<'PROMPT'
        You are a content classifier for a WhatsApp insurance assistant.
        Your only job is to classify a message as either "conversational" or "informational".

        Respond with ONLY one of these two words, nothing else.

        "informational" messages contain:
        
        - Price quotes or monetary amounts
        - Data confirmations (vehicle details, customer info, policy numbers)
        - Lists of options, coverages, or features
        - URLs or links
        - Structured data with colons or bullet points
        - Step-by-step instructions

        "conversational" messages are:
        - Insurance coverage explanations or definitions
        - Greetings, acknowledgements, or brief responses
        - Open-ended questions to gather information
        - Simple follow-up prompts
        - Empathetic or relational statements
        PROMPT;

    private const INSTRUCTIONS = <<<'PROMPT'
        You are a content classifier for a WhatsApp insurance assistant.
        Your only job is to classify a message as either "conversational" (which will be converted to audio) or "informational" (which will be sent as text).

        Respond with ONLY one of these two words, nothing else.

        Rule of precedence: If a message contains specific monetary amounts (numbers with the "$" sign) OR requests specific vehicle/personal data from the user (e.g., brand, model, year, license plate, zip code), it MUST strictly be classified as "informational".

        "conversational" messages are conceptual explanations meant to be listened to. They include:
        - Explanations of how insurance coverages work (e.g., "Terceros Completo" vs "Todo Riesgo").
        - Explanations of hypothetical scenarios or accident claims (e.g., cyclist crash, hail, total destruction).
        - Concept comparisons, EVEN IF they are formatted with bullet points or asterisks.
        - Transitions or offers to search for quotes (e.g., "Si querés, te busco las 2 mejores opciones").

        "informational" messages are hard data, exact requirements, or numbers meant to be read. They include:
        - Specific price quotes and monetary differences (e.g., "$38.544/mes").
        - Direct requests for structured data needed to quote (e.g., "Pasame marca, modelo, año, patente").
        - Detailed breakdowns of a specific policy offering for a specific car with its exact price.
        PROMPT;

    /**
     * Classify a message as 'conversational' or 'informational'.
     * Returns 'informational' on failure as a safe fallback (→ TEXT).
     */
    public function classify(string $text): string
    {
        try {
            $response = agent(instructions: self::INSTRUCTIONS)
                ->prompt($text);

            $result = strtolower(trim($response->text));

            return in_array($result, ['conversational', 'informational'], true)
                ? $result
                : 'informational';
        } catch (\Throwable $e) {
            Log::warning('ContentClassifier: LLM call failed, defaulting to informational', [
                'error' => $e->getMessage(),
                'text_preview' => mb_substr($text, 0, 100),
            ]);

            return 'informational';
        }
    }
}
