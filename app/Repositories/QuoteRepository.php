<?php

namespace App\Repositories;

use App\Models\Conversation;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\QuoteProviderRef;
use App\Models\RiskSnapshot;
use App\Traits\ConditionalLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuoteRepository
{
    use ConditionalLogger;

    /**
     * Crea la cabecera de la cotización en estado pendiente.
     *
     * @param  RiskSnapshot  $snapshot  // El snapshot del riesgo
     * @param  Conversation  $conversation  // La conversacion en donde se crea la cotización
     * @param  string  $sessionUuid  El UUID de la sesión para rastreo.
     */
    public function createPending(RiskSnapshot $snapshot, Conversation $conversation, string $sessionUuid): Quote
    {
        return Quote::create([
            'session_uuid' => $sessionUuid,
            'risk_snapshot_id' => $snapshot->id,
            'conversation_id' => $conversation->id,
            'status' => 'pending',
        ]);
    }

    /**
     * Guarda los resultados del motor de cotización de forma atómica.
     * Maneja la limpieza de alternativas previas (idempotencia) y la actualización de estado.
     *
     * @param  array  $engineResult  El resultado raw del QuotationProvider
     */
    public function saveResults(Quote $quote, array $engineResult): void
    {
        DB::transaction(function () use ($quote, $engineResult): void {

            // 1. Limpieza preventiva (idempotencia para reintentos del job)
            $quote->alternatives()->delete();
            $quote->providerRef()->delete();

            // 2. Actualizar cabecera (sin raw_response — movido a quote_provider_refs)
            $quote->update([
                'status' => 'processed',
                'external_ref_id' => $engineResult['task_id'] ?? null,
                // Los precios de las compañías valen por día calendario argentino: pasado el
                // cierre hay que recotizar. Ver Quote::endOfBusinessDay().
                'expires_at' => Quote::endOfBusinessDay(),
            ]);

            // 3. Insertar alternativas de dominio (sin campos de proveedor)
            $parsedAlternatives = $engineResult['parsed_alternatives'] ?? [];
            if ($parsedAlternatives !== []) {
                $domainAlternatives = array_map(function (array $alt): array {
                    unset($alt['external_quote_id'], $alt['external_code'], $alt['company_id'], $alt['discount_id'], $alt['requires_inspection_before_emission']);

                    return $alt;
                }, $parsedAlternatives);

                $created = $quote->alternatives()->createMany($domainAlternatives);

                // 3b. Token opaco del proveedor POR alternativa (ADR-001): el
                // quotation_result_id con el que la emisión emite la elegida +
                // el flag de inspección pre-emisión. createMany conserva el orden
                // de entrada, así que zipea posicional con $parsedAlternatives.
                foreach ($created as $index => $alternative) {
                    $parsed = $parsedAlternatives[$index] ?? [];
                    $externalQuoteId = (string) ($parsed['external_quote_id'] ?? '');
                    if ($externalQuoteId === '') {
                        continue;
                    }
                    $alternative->providerRef()->create([
                        'external_quote_id' => $externalQuoteId,
                        'company_id' => $parsed['company_id'] ?? null,
                        'discount_id' => $parsed['discount_id'] ?? null,
                        'requires_inspection_before_emission' => $parsed['requires_inspection_before_emission'] ?? false,
                    ]);
                }
            }

            // 4. Persistir referencia de proveedor a nivel quote (auditoría: raw + id de la 1ra)
            QuoteProviderRef::create([
                'quote_id' => $quote->id,
                'external_quote_id' => $parsedAlternatives[0]['external_quote_id'] ?? null,
                'raw_response' => $engineResult['raw'] ?? ['alternatives' => $parsedAlternatives],
            ]);
        });

        $this->logQuote("[QuoteRepo] Resultados guardados para Quote ID: {$quote->id}");
    }

    /**
     * Marca la cotización como fallida.
     */
    public function markAsFailed(Quote $quote, string $errorMessage): void
    {
        $quote->update([
            'status' => 'failed',
            'metadata' => ['error' => $errorMessage],
        ]);
    }

    /**
     * Abre el checkout de una alternativa: mintea el token opaco y deja la cotización lista
     * para que el cliente complete la contratación.
     *
     * Es el único lugar del código que escribe `checkout_token`. La verificación de unicidad
     * espeja a `Quote::ensurePublicToken()`: los dos tokens son credenciales de acceso a
     * páginas sin autenticación, así que una colisión silenciosa le daría a un cliente el
     * checkout de otro.
     *
     * No decide si corresponde abrirlo — eso es dominio y vive en
     * `QuoteService::crearCheckout()`.
     */
    public function marcarCheckoutPendiente(Quote $quote, QuoteAlternative $alternative): string
    {
        do {
            $token = Str::random(10);
        } while (Quote::withTrashed()->where('checkout_token', $token)->exists());

        $quote->update([
            'status' => 'checkout_pending',
            'checkout_token' => $token,
            'checkout_alternative_id' => $alternative->id,
        ]);

        return $token;
    }

    /**
     * Obtiene una cotización por ID con o sin alternativas.
     *
     * @param  int  $id  El id de la Quote
     * @param  bool  $withAlternatives  Indica si se quieren las alternativas de la Quote
     */
    public function getById(int $id, bool $withAlternatives = false): ?Quote
    {
        return $withAlternatives
            ? Quote::with('alternatives')->find($id)
            : Quote::find($id);
    }
}
