<?php

namespace App\AI\Tools;

use App\AI\Concerns\HasMockReplay;
use App\AI\Contracts\Mockable;
use App\Models\Conversation;
use App\Models\QuoteAlternative;
use App\Traits\ConditionalLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Arma los botones de WhatsApp para la presentación de cotizaciones desde
 * `quote_alternatives` (dominio) — el LLM nunca escribe títulos ni precios de
 * botón, así que no puede inventarlos ni exceder el límite de Meta. Los deja
 * en `metadata.pending_interactive`; el orquestador los levanta al retornar
 * y `SendWhatsAppMessage` los adjunta al mensaje de texto que escriba el agente.
 */
class PresentQuoteOptionsTool implements Mockable, Tool
{
    use ConditionalLogger;
    use HasMockReplay;

    public function __construct(
        private readonly Conversation $conversation,
    ) {}

    public function description(): string
    {
        return 'Prepara los botones de WhatsApp para las 2 opciones que vas a presentar. '
            .'Ejecutala ANTES de escribir el texto de presentación (el texto sale acompañado '
            .'de los botones). Requiere que hayas decidido cuál de las 2 recomendás.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'quote_id' => $schema->integer()
                ->description('ID de la cotización (quote_id) de las alternativas.')
                ->required(),
            'alternative_ids' => $schema->array()
                ->items($schema->integer())
                ->min(2)
                ->max(2)
                ->description('Los 2 quote_alternative_id que vas a presentar.')
                ->required(),
            'recommended_alternative_id' => $schema->integer()
                ->description('Cuál de los 2 anteriores es el recomendado (va primero en los botones).')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        if (($mock = $this->interceptIfReplay($request)) !== null) {
            return $mock;
        }

        $this->logToolCall($request->all());

        $quoteId = (int) $request['quote_id'];
        $alternativeIds = array_map(intval(...), (array) $request['alternative_ids']);
        $recommendedId = (int) $request['recommended_alternative_id'];

        if (count($alternativeIds) !== 2) {
            return json_encode([
                'success' => false,
                'error' => 'Se necesitan exactamente 2 alternative_ids.',
                'error_code' => 'invalid_alternative_count',
            ]);
        }

        $alternatives = QuoteAlternative::whereIn('id', $alternativeIds)
            ->where('quote_id', $quoteId)
            ->get()
            ->keyBy('id');

        if ($alternatives->count() !== 2) {
            return json_encode([
                'success' => false,
                'error' => 'Los ids no corresponden a alternativas válidas de esta cotización. Verificá con get_quote.',
                'error_code' => 'alternatives_not_found',
            ]);
        }

        // La recomendada va primero.
        $ordered = collect($alternativeIds)
            ->sortByDesc(fn (int $id): bool => $id === $recommendedId)
            ->map(fn (int $id): QuoteAlternative => $alternatives[$id]);

        $buttons = $ordered
            ->map(fn (QuoteAlternative $alt): array => [
                'id' => "alt:{$alt->id}",
                'title' => $this->buttonTitle($alt->aseguradora, (string) $alt->precio),
            ])
            ->values()
            ->all();

        $buttons[] = ['id' => 'question', 'title' => 'Tengo una pregunta'];

        $meta = $this->conversation->metadata ?? [];
        $meta['pending_interactive'] = ['buttons' => $buttons];
        $this->conversation->update(['metadata' => $meta]);

        $titles = implode(' / ', array_column($buttons, 'title'));

        return json_encode([
            'success' => true,
            'tool_output' => "Botones preparados: {$titles}. Tu próxima respuesta va a salir acompañada de esos botones. "
                .'Escribí SOLO el texto de presentación: ambas opciones con sus features, marcando la recomendada y por qué.',
        ]);
    }

    /**
     * "{Aseguradora} $12,3K" en máximo 20 caracteres (límite de Meta). Si el
     * nombre de la aseguradora no entra, se trunca — sendInteractiveButtons()
     * vuelve a truncar como red de seguridad.
     */
    private function buttonTitle(string $aseguradora, string $precio): string
    {
        $priceNum = (float) $precio;
        $priceAbbrev = $priceNum >= 1000
            ? '$'.rtrim(rtrim(number_format($priceNum / 1000, 1), '0'), '.').'K'
            : '$'.number_format($priceNum, 0);

        $title = trim("{$aseguradora} {$priceAbbrev}");
        if (mb_strlen($title) <= 20) {
            return $title;
        }

        $available = max(1, 20 - mb_strlen($priceAbbrev) - 1);

        return mb_substr($aseguradora, 0, $available).' '.$priceAbbrev;
    }

    public function mockResponse(Request $request): string
    {
        return json_encode([
            'success' => true,
            'mock' => true,
            'tool_output' => 'Botones preparados (mock).',
        ]);
    }
}
