<?php

namespace App\AI\Tools;

use App\AI\Concerns\HasMockReplay;
use App\AI\Contracts\Mockable;
use App\Models\Conversation;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Traits\ConditionalLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Arma los botones de WhatsApp para la presentación de cotizaciones desde
 * `quote_alternatives` (dominio) — el LLM nunca escribe títulos ni precios de
 * botón, así que no puede inventarlos ni exceder el límite de Meta. Los deja
 * en `metadata.pending_interactive`; el orquestador los levanta al retornar
 * y `SendWhatsAppMessage` los adjunta al mensaje de texto que escriba el agente.
 *
 * Además deja registrada la presentación en la cotización (qué par se mostró, cuál se recomendó
 * y por qué) y mintea el token de la vista pública. Antes de esto, la única traza de la
 * recomendación era `agent_execution_logs.tool_calls`, un log de auditoría sin retención.
 */
class PresentQuoteOptionsTool implements Mockable, Tool
{
    use ConditionalLogger;
    use HasMockReplay;

    /** Tope de cada razón: la card de la vista pública está diseñada para 2 a 4 oraciones. */
    private const MAX_REASON = 600;

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
            'recommended_reason' => $schema->string()
                ->description('Por qué recomendás ESA opción, en 2 a 4 oraciones, hablándole al cliente. '
                    .'OJO: se guarda tal cual y se muestra en una página web PÚBLICA que puede abrir '
                    .'cualquiera con el link. Nada de nombre, DNI, patente ni teléfono. Tampoco '
                    .'prometas topes, sublímites ni plazos que no estén en los datos del producto.')
                ->required(),
            'alternative_reason' => $schema->string()
                ->description('Por qué la OTRA es la alternativa: qué gana y qué pierde el cliente frente '
                    .'a la recomendada. Mismas reglas que recommended_reason.')
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

        // Sin este guard el sortByDesc de abajo no reordena nada y la recomendación queda mal
        // atribuida en silencio.
        if (! in_array($recommendedId, $alternativeIds, true)) {
            return json_encode([
                'success' => false,
                'error' => 'El recommended_alternative_id tiene que ser uno de los 2 alternative_ids.',
                'error_code' => 'recommended_not_in_set',
            ]);
        }

        $recommendedReason = trim((string) ($request['recommended_reason'] ?? ''));
        $alternativeReason = trim((string) ($request['alternative_reason'] ?? ''));

        // Las razones son load-bearing en la vista pública: sin ellas el bloque citado de la
        // card queda hueco.
        if ($recommendedReason === '' || $alternativeReason === '') {
            return json_encode([
                'success' => false,
                'error' => 'Faltan recommended_reason y/o alternative_reason.',
                'error_code' => 'missing_reason',
            ]);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return json_encode([
                'success' => false,
                'error' => 'No existe esa cotización.',
                'error_code' => 'quote_not_found',
            ]);
        }

        $otherId = (int) collect($alternativeIds)->first(fn (int $id): bool => $id !== $recommendedId);

        // Se truncan y no se rechazan: que el modelo escriba de más no justifica romper el turno.
        //
        // `presented_at` NO se sella acá: lo sella quien despacha el mensaje, cuando el mensaje
        // sale. Es la marca por la que `NotifyClientQuoteReady` decide si tiene que presentar, y
        // si la escribiera esta tool bastaría con que el turno muriera después para que el
        // reintento la diera por entregada y el cliente no recibiera nada. Pasó en producción:
        // ver ROADMAP, bitácora 2026-09-02.
        $quote->update([
            'recommended_alternative_id' => $recommendedId,
            'presented_alternative_ids' => [$recommendedId, $otherId],
            'presentation_reasons' => [
                (string) $recommendedId => $this->acotar($recommendedReason),
                (string) $otherId => $this->acotar($alternativeReason),
            ],
        ]);

        // El link a la vista pública sale en un mensaje aparte, inmediatamente después del texto
        // de presentación. Se arma acá y lo despacha el llamador del orquestador: el LLM nunca
        // lo escribe, así no puede deformarlo ni inventarlo.
        $publicLink = route('cotizaciones.show', ['token' => $quote->ensurePublicToken()]);

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

        // El sello temporal lo lee `InsuranceOrchestrator::pullPending()` para descartar lo que
        // quedó huérfano: estos pendientes los consume el final del turno, y si el turno muere en
        // el medio se quedan acá esperando pegarse al próximo mensaje que salga, sea cual sea.
        $meta = $this->conversation->metadata ?? [];
        $meta['pending_interactive'] = ['buttons' => $buttons];
        $meta['pending_public_link'] = $publicLink;
        $meta['pending_at'] = now()->toIso8601String();
        $this->conversation->update(['metadata' => $meta]);

        $titles = implode(' / ', array_column($buttons, 'title'));

        // El agente se entera de que el link sale, pero nunca ve la URL: si la tuviera la pegaría
        // en el chat con su propio formato, y el mensaje de abajo dejaría de tener sentido.
        return json_encode([
            'success' => true,
            'tool_output' => "Botones preparados: {$titles}. Tu próxima respuesta va a salir acompañada de esos botones. "
                .'Las razones quedaron guardadas. '
                .'Escribí SOLO el texto de presentación: ambas opciones con sus features, marcando la recomendada y por qué. '
                ."\n\n".$this->comoNombrarlas($ordered)."\n\n"
                .'Justo después de tu mensaje le llega al cliente, en un mensaje aparte, el link a la comparación '
                .'completa de las opciones. Ya está encolado: no escribas ninguna URL ni lo prometas como algo que '
                .'vas a mandar. Si te sirve, podés cerrar con algo del estilo "abajo te paso el detalle completo".',
        ]);
    }

    /**
     * Recorta una razón al tope, contando los puntos suspensivos adentro del límite y no
     * después, para que lo guardado nunca supere MAX_REASON.
     */
    private function acotar(string $reason): string
    {
        if (mb_strlen($reason) <= self::MAX_REASON) {
            return $reason;
        }

        return mb_substr($reason, 0, self::MAX_REASON - 1).'…';
    }

    /**
     * "{Aseguradora} $12,3K" en máximo 20 caracteres (límite de Meta). Si el
     * nombre de la aseguradora no entra, se trunca — sendInteractiveButtons()
     * vuelve a truncar como red de seguridad.
     */
    /**
     * Le entrega al agente el nombre exacto de cada opción, armado desde el dominio.
     *
     * El texto de presentación lo escribe el LLM, y sin esto escribe el NIVEL de cobertura
     * —"Galicia Terceros Completos"— en vez del producto —"Galicia C Clima"—. El cliente se queda
     * sin saber cómo se llama su plan, y cuando vuelve a preguntar por él no puede nombrarlo:
     * en la conversación 25 preguntó por "la cobertura premium", el agente lo resolvió contra las
     * 137 alternativas de la cotización y contestó sobre `Premium Max` de OTRA compañía. Hicieron
     * falta tres turnos para desarmarlo.
     *
     * Va acá y no en el prompt porque el `tool_output` es el canal que mejor obedece —ver
     * CLAUDE.md, el aviso de espera de la cotización— y porque así el modelo no redacta el
     * nombre: lo copia.
     *
     * No va en el botón: WhatsApp corta los títulos en 20 caracteres y `buttonTitle()` ya gasta
     * 5 en el precio. De los 71 títulos distintos de producción, 41 pasan los 20 caracteres, así
     * que `Todo Riesgo Franquicia 4%` quedaría en `Todo Riesgo Fra`.
     *
     * @param  Collection<int, QuoteAlternative>  $ordered  la recomendada primero
     */
    private function comoNombrarlas(Collection $ordered): string
    {
        $listado = $ordered
            ->values()
            ->map(fn (QuoteAlternative $alt, int $i): string => '  '
                .($i + 1).'. '.trim("{$alt->aseguradora} {$alt->titulo}")
                .($i === 0 ? '  (la recomendada)' : ''))
            ->implode("\n");

        return "Nombrá cada opción EXACTAMENTE así, que es como la identifica la compañía:\n"
            .$listado."\n"
            .'No los abrevies ni los reemplaces por el nivel de cobertura ("Terceros Completos", '
            .'"Todo Riesgo"). Si el cliente no ve el nombre del producto, después no puede preguntar '
            .'por él.';
    }

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
