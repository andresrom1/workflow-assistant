<?php

namespace App\Services;

use App\Contracts\EmissionProvider;
use App\Enums\InspectionPhotoStatus;
use App\Jobs\CapturePendingPolicyDocuments;
use App\Jobs\SendPolicyDocumentsToClient;
use App\Models\CheckoutSession;
use App\Models\InspectionPhoto;
use App\Models\Poliza;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\RiskSnapshot;
use App\Support\DocumentoIdentidad;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Orquesta la emisión de póliza: arma el request neutro desde el checkout + el
 * snapshot + la alternativa elegida, lo despacha por el puerto {@see EmissionProvider}
 * (agnóstico de proveedor) y materializa el resultado del lado de workflow-assistant.
 *
 * Frontera de scope:
 *  - Titular completo (`first_name`/`last_name`, `phone_prefix`/`phone_number`,
 *    `birthdate`/`sex_id`/`tax_condition_id`) y `has_gnc`: capturados en checkout
 *    (D1/D2, WS-B) y mapeados acá al request neutro.
 *  - La materialización en cartera (find-or-create `Risk` + `Poliza`-referencia) se
 *    delega a {@see PolicyReferenceService} (dominio cartera, separado del acto de
 *    emitir). Acá solo se transiciona el `Quote` y se dispara esa materialización.
 *
 * El dominio nunca importa una clase Visred: este servicio habla el shape neutro
 * del puerto. Ver docs/v2/10 §3/§4/§5/§6.
 */
class PolizaEmisionService
{
    public function __construct(
        private readonly EmissionProvider $emissionProvider,
        private readonly PolicyReferenceService $policyReference,
        private readonly PolicyDocumentService $policyDocuments,
    ) {}

    /**
     * Emite la póliza para un checkout completado.
     *
     * @return array<string, mixed> Resultado neutro de la emisión.
     *
     * @throws RuntimeException Si falta el quotation_result_id o la emisión falla.
     */
    public function emitir(Quote $quote, CheckoutSession $session): array
    {
        // D4.2 — idempotencia: EmitirPoliza reintenta ante excepción. Si el quote ya
        // fue emitido (ya hay Poliza-referencia), NO re-emitir → evita una doble
        // pre-venta real contra el proveedor. Guard por estado (mínimo viable).
        // TODO (ventana de carrera): un crash entre emit() y persistEmission() dejaría
        // el estado sin marcar y el guard no cubriría ese reintento. Cerrar con una
        // clave de idempotencia (p. ej. lock por quote) si se vuelve un problema real.
        if ($quote->status === 'poliza_emitida') {
            return $this->stashedResult($quote);
        }

        $alternative = $this->chosenAlternative($quote, $session);
        $quotationResultRef = $alternative->providerRef?->external_quote_id;

        if ($quotationResultRef === null || $quotationResultRef === '') {
            throw new RuntimeException("No hay quotation_result_id para emitir el quote {$quote->id}.");
        }

        $result = $this->emissionProvider->emit($this->buildRequest($quote, $session, $alternative));

        if ($result['status'] !== 'SUCCESS') {
            // Falla → excepción para que EmitirPoliza reintente. (La inspección
            // post-emisión la resuelve el adapter internamente con el presale vivo.)
            throw new RuntimeException("La emisión del quote {$quote->id} no fue exitosa.");
        }

        $poliza = $this->persistEmission($quote, $alternative, $result);

        // Documentos oficiales capturados en la emisión (por el adapter, con el
        // presale vivo y sin que salga de él): se persisten en cartera. Best-effort.
        $this->policyDocuments->storeFromEmission($poliza, $result['documents']);

        // Aviso al cliente por WhatsApp con los documentos ya disponibles. Si todo
        // quedó pendiente (sin documentos aún), el job es un no-op — el aviso real
        // ocurre cuando CapturePendingPolicyDocuments los capture.
        $this->notifyClientOfDocuments($poliza, $quote);

        // Documentos que la compañía todavía estaba generando (descarga async): se
        // persiste la referencia opaca del proveedor y se difiere la captura a un job
        // con reintentos. El token es opaco para el dominio (su valor es el presale_id,
        // pero acá no se interpreta). Ver docs/v2/08 §6.
        $this->scheduleDocumentRetry($poliza, $result['pending_documents']);

        return $result;
    }

    /**
     * Difiere la captura de los documentos que no estuvieron listos al emitir: persiste
     * la referencia opaca del proveedor (token + `kind` pendientes) y encola el job de
     * reintento. No-op si no quedó nada pendiente.
     *
     * @param  array<string, mixed>  $pending  Shape `pending_documents` de la emisión.
     */
    private function scheduleDocumentRetry(Poliza $poliza, array $pending): void
    {
        $token = is_scalar($pending['token'] ?? null) ? (string) $pending['token'] : '';
        $kinds = array_values(array_filter(
            (array) ($pending['kinds'] ?? []),
            static fn ($kind): bool => is_string($kind) && $kind !== '',
        ));

        if ($token === '' || $kinds === []) {
            return;
        }

        $poliza->providerRef()->updateOrCreate(
            ['poliza_id' => $poliza->id],
            [
                'document_token' => $token,
                'product_id' => is_scalar($pending['product_id'] ?? null) ? (string) $pending['product_id'] : 'auto',
                'pending_document_kinds' => $kinds,
            ],
        );

        CapturePendingPolicyDocuments::dispatch($poliza->id)
            ->delay(now()->addSeconds((int) config('visred.document_retry_delay', 60)));
    }

    /**
     * Despacha el envío de documentos por WhatsApp a la conversación de origen del
     * quote (`quotes.conversation_id` es NOT NULL — el canal es siempre WhatsApp).
     */
    private function notifyClientOfDocuments(Poliza $poliza, Quote $quote): void
    {
        if (! $poliza->documents()->where('visible_to_client', true)->exists()) {
            return;
        }

        SendPolicyDocumentsToClient::dispatch($poliza->id, $quote->conversation_id)
            ->onQueue('whatsapp-outbound');
    }

    /**
     * Reconstruye el resultado neutro de una emisión ya materializada (guard de
     * idempotencia): lo arma desde la `Poliza`-referencia en cartera, sin re-emitir
     * y sin acoplar la referencia al `Quote` (esa vive en su propio dominio).
     *
     * @return array<string, mixed>
     */
    private function stashedResult(Quote $quote): array
    {
        $poliza = Poliza::query()->where('quote_id', $quote->id)->first();
        $meta = is_array($poliza?->metadata) ? $poliza->metadata : [];

        return [
            'task_id' => '',
            'status' => 'SUCCESS',
            'proposal_number' => $meta['proposal_number'] ?? null,
            'policy_number' => $poliza?->numero,
            'emission_status' => $meta['emission_status'] ?? null,
            'requires_inspection_after_emission' => $meta['requires_inspection_after_emission'] ?? false,
            'company_id' => $poliza?->company_id,
            // Re-entrada idempotente: la captura de documentos ya corrió en la 1ª
            // emisión; no se re-captura (el presale ya no vive). El reintento diferido,
            // si quedó algo pendiente, lo lleva el job desde la `poliza_provider_refs`.
            'documents' => [],
            'pending_documents' => ['token' => '', 'product_id' => 'auto', 'kinds' => []],
            'raw' => ['source' => 'stash (idempotencia)'],
        ];
    }

    /**
     * La cobertura elegida en checkout (fuente: la sesión; fallback: el quote).
     */
    private function chosenAlternative(Quote $quote, CheckoutSession $session): QuoteAlternative
    {
        $alternativeId = $session->quote_alternative_id ?? $quote->checkout_alternative_id;

        $alternative = $quote->alternatives()
            ->with('providerRef')
            ->where('id', $alternativeId)
            ->first();

        if (! $alternative instanceof QuoteAlternative) {
            throw new RuntimeException("El quote {$quote->id} no tiene una alternativa elegida válida.");
        }

        return $alternative;
    }

    /**
     * Quote + CheckoutSession + snapshot + alternativa elegida → request neutro del
     * puerto de emisión. NO menciona campos de Visred (el adapter traduce). Lo que
     * el checkout no captura queda fuera (deuda scope checkout).
     *
     * @return array<string, mixed>
     */
    private function buildRequest(Quote $quote, CheckoutSession $session, QuoteAlternative $alternative): array
    {
        $snapshot = $quote->riskSnapshot;
        $ref = $alternative->providerRef;

        $request = [
            'quotation_result_ref' => (string) $ref->external_quote_id,
            'holder' => [
                'document_number' => $this->emissionDocumentNumber($snapshot, $session),
                'first_name' => $session->first_name,
                'last_name' => $session->last_name,
                'birthdate' => $session->birthdate?->format('Y-m-d'),
                'sex_id' => $session->sex_id,
                'tax_condition_id' => $session->tax_condition_id,
                'email' => $session->email,
                'phone_prefix' => $session->phone_prefix,
                'phone_number' => $session->phone_number,
            ],
            // Domicilio del tomador (calle/nro/cp legal). El CP que TARIFA es el de
            // guarda del riesgo (`risk_zip_code`), no éste: qué `zip_code` viaja al
            // proveedor lo decide el adapter (regla de negocio confinada ahí). Ver docs/v2/11.
            'address' => [
                'zip_code' => $session->domicilio_cp,
                'street_name' => $session->domicilio_calle,
                'street_number' => $session->domicilio_numero,
                'risk_zip_code' => $snapshot?->codigo_postal,
            ],
            'vehicle' => [
                'plate' => $snapshot?->vehicle->patente,
                'motor' => $session->vehiculo_nro_motor,
                'chassis' => $session->vehiculo_nro_chasis,
                'has_gnc' => $session->has_gnc,
            ],
            'payment' => $this->buildPayment($session),
        ];

        // Descuento elegido en cotización (DiscountPolicy): se manda en la emisión
        // para que la compañía aplique la misma bonificación (precio cotizado == cobrado).
        if (is_string($ref->discount_id) && $ref->discount_id !== '') {
            $request['discount_id'] = $ref->discount_id;
        }

        // D4.1 — inspección: pasamos los ingredientes neutros (company_id opaco +
        // product + fotos de dominio + `requires_before`). El adapter resuelve TODO el
        // ciclo: si la cobertura lo exige embebe el before en el emit(); la inspección
        // post-emisión la sube él con el presale vivo. El dominio no arma inspecciones
        // ni ve el presale.
        $photos = $this->confirmedPhotos($quote);
        if (is_string($ref->company_id) && $ref->company_id !== '' && $photos->isNotEmpty()) {
            $request['inspection_photos'] = [
                'company_id' => $ref->company_id,
                'product_id' => 'auto',
                'requires_before' => $ref->requires_inspection_before_emission === true,
                'photos' => $photos,
            ];
        }

        return $request;
    }

    /**
     * DNI a mandar en la emisión. Preferimos el MISMO valor que ya viajó en la
     * cotización (`$snapshot->dni`, copia de `Customer.dni` ya normalizada por
     * `Customer::saving`) — así "coincida con el de la cotización" (lo que exige
     * Visred) es una garantía byte a byte, no una esperanza. Si un cliente dio el
     * CUIL en el chat y el DNI (sin el wrapper) en el checkout, son dígitos
     * distintos aunque sea la misma persona — mandar el del checkout ahí rompería
     * igual. Solo cuando la cotización se hizo SIN DNI (`person_holder` omitido,
     * ver VisredQuotationProvider) no hay nada que igualar, y se manda el del
     * checkout normalizado (verificado en sandbox que Visred lo acepta: ROADMAP
     * Bitácora 2026-07-19).
     */
    private function emissionDocumentNumber(?RiskSnapshot $snapshot, CheckoutSession $session): ?string
    {
        $snapshotDni = DocumentoIdentidad::normalizar($snapshot?->dni);

        return $snapshotDni ?? DocumentoIdentidad::normalizar($session->dni) ?? $session->dni;
    }

    /**
     * Fotos de inspección confirmadas del checkout (dominio). Las consume tanto la
     * inspección before-emisión (embebida en el emit) como la post-emisión.
     *
     * @return Collection<int, InspectionPhoto>
     */
    private function confirmedPhotos(Quote $quote): Collection
    {
        return InspectionPhoto::query()
            ->where('quote_id', $quote->id)
            ->where('status', InspectionPhotoStatus::Confirmed)
            ->get();
    }

    /**
     * Pago neutro desde la tarjeta cifrada del checkout. `cc_expiry` ("MM/YY")
     * se parte en mes/año. El adapter aplana a los campos de Visred.
     *
     * @return array<string, mixed>
     */
    private function buildPayment(CheckoutSession $session): array
    {
        $card = [
            'brand' => $session->cc_brand,
            'holder' => $session->cc_holder_name,
            'number' => $session->cc_pan,
        ];

        $expiry = (string) $session->cc_expiry;
        if (str_contains($expiry, '/')) {
            [$month, $year] = explode('/', $expiry, 2);
            $card['expire_month'] = (int) $month;
            $card['expire_year'] = 2000 + (int) $year;
        }

        return ['method' => 'tarjeta', 'card' => $card];
    }

    /**
     * Tras emitir: transiciona el `Quote` y materializa la referencia de póliza en
     * cartera (find-or-create `Risk` + `Poliza`) vía {@see PolicyReferenceService}.
     * La referencia vive en su propio dominio (doc 10 §4/§5), no en `Quote.metadata`.
     *
     * @param  array<string, mixed>  $result
     */
    private function persistEmission(Quote $quote, QuoteAlternative $alternative, array $result): Poliza
    {
        $quote->update(['status' => 'poliza_emitida']);
        $poliza = $this->policyReference->materialize($quote, $alternative, $result);

        Log::info('PolizaEmisionService: emisión materializada en cartera', [
            'quote_id' => $quote->id,
            'risk_id' => $poliza->risk_id,
            'poliza_id' => $poliza->id,
            'policy_number' => $result['policy_number'] ?? null,
        ]);

        return $poliza;
    }
}
