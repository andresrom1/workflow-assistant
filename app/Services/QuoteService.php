<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use App\Repositories\QuoteRepository;
use App\Repositories\RiskSnapshotRepository;
use App\Services\Quote\Strategies\ApiQuoteResolution;
use App\Traits\ConditionalLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuoteService
{
    use ConditionalLogger;

    public function __construct(
        private readonly RiskSnapshotRepository $snapshotRepo,
        private readonly QuoteRepository $quoteRepo,
        private readonly ApiQuoteResolution $apiStrategy,
    ) {}

    /**
     * Inicia el proceso de cotización tomando un snapshot del riesgo actual
     * y creando una Quote en estado 'pending'.
     */
    public function createPendingQuote(Conversation $conversation, Customer $customer, Vehicle $vehicle, string $sessionUuid, ?string $coveragePreference = null): Quote
    {
        $quote = DB::transaction(function () use ($conversation, $customer, $vehicle, $sessionUuid, $coveragePreference): Quote {
            $snapshot = $this->snapshotRepo->createFromEntities($customer, $vehicle, $coveragePreference);

            return $this->quoteRepo->createPending($snapshot, $conversation, $sessionUuid);
        });

        $this->logQuotes("[QuoteService🫰] Created pending Quote ID: {$quote->id}");

        return $quote;
    }

    /**
     * Resuelve la cotización. Hoy hay una única estrategia (`api`);
     * el seam queda listo para enchufar Visred detrás del mismo punto.
     */
    public function resolveQuote(Quote $quote, RiskSnapshot $snapshot): bool
    {
        Log::info("[QuoteService] Resolviendo Quote #{$quote->id} con estrategia: {$this->apiStrategy->getName()}");

        try {
            $this->apiStrategy->resolve($quote, $snapshot);

            $this->auditarVocabulario($quote);

            return true;
        } catch (\Throwable $e) {
            Log::error("[QuoteService] Error resolviendo Quote #{$quote->id}", [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Canario del vocabulario de coberturas del proveedor.
     *
     * El diff de la vista pública es una diferencia de conjuntos sobre `features_tags`, sin
     * diccionario de sinónimos. Eso vale mientras el proveedor mande la misma cobertura con el
     * mismo texto exacto en todas las compañías. Cuando deja de valer, el diff no se rompe: miente
     * en silencio, y reporta como diferencia algo que las dos opciones cubren igual.
     *
     * No traduce ni normaliza nada — solo avisa. La decisión de qué hacer con un tag nuevo es
     * humana; esto es para enterarse el día que aparece y no seis semanas después.
     */
    private function auditarVocabulario(Quote $quote): void
    {
        /** @var list<string> $conocidos */
        $conocidos = config('quotes.tags_conocidos', []);

        // Índice por forma canónica (sin mayúsculas ni acentos) para distinguir "tag nuevo" de
        // "el mismo tag escrito distinto", que son problemas distintos.
        $porForma = [];
        foreach ($conocidos as $tag) {
            $porForma[$this->formaCanonica($tag)] = $tag;
        }

        $vistos = [];

        foreach ($quote->alternatives()->get() as $alternative) {
            foreach ($alternative->features_tags ?? [] as $tag) {
                if (isset($vistos[$tag])) {
                    continue;
                }
                $vistos[$tag] = true;

                $contexto = [
                    'quote_id' => $quote->id,
                    'tag' => $tag,
                    'aseguradora' => $alternative->aseguradora,
                ];

                if (in_array($tag, $conocidos, true)) {
                    // Un tag compuesto funde dos conceptos que el resto de las compañías trae
                    // separados, así que el diff los cuenta como distintos aunque cubran lo mismo.
                    // Se avisa aunque el tag ya sea conocido: el problema es la forma, no la
                    // novedad.
                    if (preg_match('/\s(y|o)\s/u', $tag) === 1) {
                        Log::warning('coverage.tag_compuesto', $contexto);
                    }

                    continue;
                }

                $similar = $porForma[$this->formaCanonica($tag)] ?? null;

                if ($similar !== null) {
                    // El caso peor: el diff los ve como coberturas distintas.
                    Log::warning('coverage.tag_variante', $contexto + ['variante_de' => $similar]);

                    continue;
                }

                Log::warning('coverage.tag_desconocido', $contexto);
            }
        }
    }

    /** Minúsculas y sin acentos: dos escrituras del mismo tag colapsan a la misma forma. */
    private function formaCanonica(string $tag): string
    {
        $sinAcentos = strtr(
            mb_strtolower(trim($tag)),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']
        );

        return preg_replace('/\s+/u', ' ', $sinAcentos) ?? $sinAcentos;
    }

    /**
     * Actualiza la preferencia de cobertura en el snapshot de una cotización.
     */
    public function updateSnapshotPreference(Quote $quote, string $preference): void
    {
        $this->snapshotRepo->updateCoveragePreference($quote->riskSnapshot, $preference);
    }

    /**
     * Expira las quotes abiertas de la conversación. Se usa al revertir una etapa
     * (el cliente corrigió el vehículo o la cobertura): la cotización en curso ya
     * no representa el riesgo real y debe descartarse. Idempotente.
     */
    public function expireOpenQuotes(Conversation $conversation): int
    {
        return $conversation->quotes()
            ->whereIn('status', ['pending', 'processed', 'checkout_pending'])
            ->update(['status' => 'expired']);
    }

    public function getRaw(Quote $quote): array
    {
        $quote->loadMissing('providerRef', 'alternatives');

        if ($quote->alternatives->isEmpty()) {
            return [
                'status' => $quote->status,
                'message' => 'La cotización aún está en proceso.',
            ];
        }

        return [
            'external_quote_id' => $quote->providerRef?->external_quote_id,
            'raw_response' => $quote->providerRef?->raw_response,
            'alternatives' => $quote->alternatives->toArray(),
        ];
    }

    /**
     * Obtiene una cotización por ID.
     *
     * @param  int  $quote  ID de la cotización.
     * @param  bool  $withAlternatives  Indica si se deben incluir las alternativas.
     * @return Quote La cotización obtenida.
     */
    public function getQuote(int $quote, bool $withAlternatives = false): Quote
    {
        return $this->quoteRepo->getById($quote, $withAlternatives);
    }

    /**
     * Abre el checkout de una alternativa. Punto único: lo llaman el agente por WhatsApp
     * (WhatsAppAdapter), el path OpenAI (AgentToolAdapter) y el CTA "La quiero" de la vista
     * pública (PublicQuoteController). Antes estaba duplicado en los dos adapters.
     *
     * Agnóstico de canal: devuelve el resultado y no le habla al cliente. Cada llamador
     * traduce el `error_code` al formato que le corresponde.
     *
     * OJO — acá se escribe `checkout_done` en el `ai_state`, y no en `CheckoutTool`
     * como manda la convención de "el estado lo escribe la tool". Es a propósito: el checkout
     * también se abre desde la web, donde no hay tool que corra. Si el flag quedara en la tool,
     * el agente no se enteraría de un checkout iniciado desde el comparador y seguiría vendiendo.
     *
     * @return array{ok: true, token: string, url: string}|array{ok: false, error_code: string, error: string}
     */
    public function crearCheckout(int $quoteId, int $alternativeId): array
    {
        $quote = $this->quoteRepo->getById($quoteId, withAlternatives: true);

        if ($quote === null || ! in_array($quote->status, ['processed', 'checkout_pending'], true)) {
            return [
                'ok' => false,
                'error_code' => 'quote_not_found',
                'error' => 'No se encontró una cotización válida con ese ID para esta sesión.',
            ];
        }

        // Los precios valen por el día calendario argentino en que se cotizó. Pasado el cierre
        // la tarifa puede haber cambiado, así que no se abre el checkout: hay que recotizar.
        if (! $quote->isVigente()) {
            return [
                'ok' => false,
                'error_code' => 'quote_expired',
                'error' => 'Esta cotización venció: los precios valen por el día en que se cotizó. '
                    .'Hay que hacer una cotización nueva.',
            ];
        }

        $alternative = $quote->alternatives->firstWhere('id', $alternativeId);

        if ($alternative === null) {
            return [
                'ok' => false,
                'error_code' => 'alternative_not_found',
                'error' => 'La alternativa seleccionada no corresponde a la cotización indicada.',
            ];
        }

        $token = $this->quoteRepo->marcarCheckoutPendiente($quote, $alternative);

        $quote->conversation?->updateAiState(['checkout_done' => true]);

        $this->logQuotes("[QuoteService🫰] Checkout abierto para Quote #{$quote->id}, alternativa #{$alternative->id}");

        return [
            'ok' => true,
            'token' => $token,
            'url' => route('checkout.show', ['token' => $token]),
        ];
    }
}
