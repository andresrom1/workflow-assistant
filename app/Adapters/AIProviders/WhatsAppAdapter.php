<?php

namespace App\Adapters\AIProviders;

use App\Contracts\AIProviderAdapterInterface;
use App\Contracts\Quotability;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\RiskProviderRef;
use App\Models\Vehicle;
use App\Repositories\ConversationRepository;
use App\Services\CoveragePreferenceService;
use App\Services\CustomerIdentificationService;
use App\Services\PlateNormalizerService;
use App\Services\Quotability\QuotabilityResult;
use App\Services\Quotability\QuotabilityStatus;
use App\Services\QuoteService;
use App\Services\VehicleIdentificationService;
use App\Traits\ConditionalLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WhatsAppAdapter implements AIProviderAdapterInterface
{
    use ConditionalLogger;

    public function __construct(
        private readonly CustomerIdentificationService $customerService,
        private readonly VehicleIdentificationService $vehicleService,
        private readonly ConversationRepository $conversationRepo,
        private readonly QuoteService $quoteService,
        private readonly CoveragePreferenceService $coverageService,
        private readonly PlateNormalizerService $plate,
        private readonly Quotability $quotability,
    ) {}

    /**
     * Entry point para llamadas directas por herramienta (compatibilidad con la interfaz).
     * En el flujo WhatsApp, los sub-agentes llaman a los métodos directamente.
     */
    public function handleToolCall(array $payload, string $toolName): array
    {
        $data = $this->normalizePayload($payload);
        $conversation = $this->conversationRepo->findOrCreateByExternalId(
            $data['external_conversation_id'],
            'whatsapp'
        );

        $this->logAdapter("WhatsApp tool llamado: {$toolName}", [
            'payload' => $payload,
            'conversation_id' => $conversation->id,
        ]);

        try {
            return match ($toolName) {
                'identify_customer' => $this->identifyCustomer($data, $conversation),
                'identify_vehicle' => $this->identifyVehicle($data, $conversation),
                'coverage_preference' => $this->coveragePreference($data, $conversation),
                'get_quote' => $this->getQuote($data),
                'checkout' => $this->checkout($data, $conversation),
                default => $this->formatError("Herramienta no soportada: {$toolName}", 'tool_not_found'),
            };
        } catch (InvalidArgumentException $e) {
            Log::warning('WhatsApp Adapter: validación fallida', ['error' => $e->getMessage()]);

            return $this->formatError($e->getMessage(), 'validation_error');
        } catch (\Exception $e) {
            Log::error('WhatsApp Adapter: error interno', [
                'msg' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->formatError('Error interno del servidor', 'server_error');
        }
    }

    // =========================================================================
    // TOOL HANDLERS
    // =========================================================================

    /**
     * Identifica (o crea) un cliente y lo vincula a la conversación activa.
     *
     * @param  array  $data  Datos normalizados del payload
     * @param  Conversation  $conversation  La conversación del usuario de WhatsApp
     */
    public function identifyCustomer(array $data, Conversation $conversation): array
    {
        $data = $this->validatePayload($data, [
            'identifier_type' => 'required|string|in:email,phone,dni',
            'identifier_value' => 'required|string',
            'external_conversation_id' => 'required|string',
            'ext_user_id' => 'nullable|string',
        ]);

        // Árbol create/enrich/merge (docs/v2/12 §5.3): si la conversación ya tiene tomador y el
        // identificador pertenece a otra fila, se fusionan; si no lo posee nadie, se enriquece
        // esa fila en vez de crear una nueva (evita el duplicado huérfano del "create+repoint").
        $customer = $this->customerService->resolveForConversation(
            $data['identifier_type'],
            $data['identifier_value'],
            $conversation->customer,
        );

        $this->conversationRepo->linkCustomer($conversation->id, $customer->id);

        return $this->formatSuccess('Cliente identificado correctamente.');
    }

    /**
     * Identifica (o crea) un vehículo, lo asocia al cliente y abre una cotización pendiente.
     *
     * @param  array  $data  Datos normalizados del payload
     * @param  Conversation  $conversation  La conversación activa para obtener el cliente
     */
    public function identifyVehicle(array $data, Conversation $conversation): array
    {
        $customer = $conversation->customer;

        if (! $customer instanceof Customer) {
            return $this->formatError(
                'No se ha identificado un cliente para asignar el vehículo.',
                'missing_customer'
            );
        }

        $data = $this->validatePayload($data, [
            'patente' => ['required', 'string', 'regex:/^([A-Z]{3}\s?\d{3}|[A-Z]{2}\s?\d{3}\s?[A-Z]{2})$/i'],
            'marca' => 'required|string|max:100',
            'modelo' => 'required|string|max:100',
            'version' => 'required|string|max:100',
            'year' => 'required|integer|min:1900|max:'.(date('Y') + 1),
            'combustible' => 'required|string|in:nafta,Nafta,diesel,Diesel,gnc,GNC,electrico,Electrico,hibrido,Hibrido',
            'codigo_postal' => 'required|string|max:10',
            'sessionUuid' => 'required|string',
        ]);

        $vehicle = $this->vehicleService->findOrCreate($customer, $data);

        if (! $vehicle->wasRecentlyCreated) {
            $this->vehicleService->updateVehicle($vehicle, $customer, $data);
            $this->logAdapter('WhatsApp: vehículo actualizado/transferido.', ['patente' => $vehicle->patente]);
        }

        // Gate de quotability (agnóstico): ¿algún proveedor cotiza este auto? El
        // canal SOLO ve el tri-estado — el token/proveedor quedan en el resolver.
        $quotability = $this->quotability->check($vehicle);

        return match ($quotability->status) {
            QuotabilityStatus::Quotable => $this->onQuotable($conversation, $customer, $vehicle, $data['sessionUuid'], $quotability),
            QuotabilityStatus::NeedsFact => $this->onNeedsFact($vehicle, $quotability),
            QuotabilityStatus::NotQuotable => $this->onNotQuotable($vehicle),
        };
    }

    /**
     * Quotable: crea la cotización pendiente, persiste el token opaco del
     * proveedor (genérico, en risk_provider_refs) y promete la oferta.
     */
    private function onQuotable(Conversation $conversation, Customer $customer, Vehicle $vehicle, string $sessionUuid, QuotabilityResult $result): array
    {
        $quote = $this->quoteService->createPendingQuote($conversation, $customer, $vehicle, $sessionUuid);

        RiskProviderRef::updateOrCreate(
            ['risk_snapshot_id' => $quote->risk_snapshot_id, 'provider' => $result->provider],
            ['external_vehicle_ref' => $result->externalRef],
        );

        return $this->formatSuccess(
            "Vehículo registrado correctamente. Cotización #{$quote->id} iniciada. "
            .'Indagá al cliente sobre la cobertura deseada para proceder con la oferta.',
            ['quote_id' => $quote->id]
        );
    }

    /**
     * NeedsFact: ambigüedad reencuadrada como hecho de dominio faltante. No
     * promete cotización; el agente debe preguntar el dato (p.ej. transmisión).
     */
    private function onNeedsFact(Vehicle $vehicle, QuotabilityResult $result): array
    {
        $options = $result->options !== [] ? ' ('.implode(' / ', $result->options).')' : '';

        return $this->formatSuccess(
            "Vehículo registrado. Para cotizar necesito un dato más: ¿{$result->missingFact}?{$options} "
            .'Preguntáselo al cliente antes de avanzar a la cobertura.',
            ['needs_fact' => $result->missingFact]
        );
    }

    /**
     * NotQuotable: rama honesta. No mentimos identidad ("te tengo el auto"),
     * pero no prometemos una cotización que no va a llegar.
     */
    private function onNotQuotable(Vehicle $vehicle): array
    {
        return $this->formatSuccess(
            "Tengo registrado tu {$vehicle->marca} {$vehicle->modelo} {$vehicle->year}, pero no puedo "
            .'cotizarlo automáticamente en este momento. Ofrecé derivar la consulta a un asesor.',
            ['quotable' => false]
        );
    }

    /**
     * Persiste la preferencia de cobertura del cliente y dispara la resolución vía API.
     *
     * @param  array  $data  Datos normalizados del payload
     * @param  Conversation  $conversation  La conversación activa para obtener el cliente
     */
    public function coveragePreference(array $data, Conversation $conversation): array
    {
        $data = $this->normalizePayload($data);
        $data = $this->validatePayload($data, [
            'patente' => 'required|string',
            'preference' => 'required|string',
        ]);

        $data['patente'] = $this->plate->normalize($data['patente']);

        $conversation->load('customer.vehicles');

        $vehicle = $conversation->customer->vehicles
            ->where('patente', $data['patente'])
            ->first();

        if (! $vehicle) {
            return $this->formatError(
                "No se encontró un vehículo con patente '{$data['patente']}' en esta conversación.",
                'missing_vehicle'
            );
        }

        $quoteId = null;

        try {
            DB::transaction(function () use ($conversation, $vehicle, $data, &$quoteId): void {
                $this->coverageService->saveCoveragePreference(
                    $conversation->id,
                    $vehicle->id,
                    $data['preference']
                );

                $quote = $conversation->quotes()
                    ->where('status', 'pending')
                    ->latest()
                    ->first();

                if ($quote) {
                    $this->quoteService->updateSnapshotPreference($quote, $data['preference']);
                    $quoteId = $quote->id;
                }
            });
        } catch (\Throwable $e) {
            Log::error('WhatsApp Adapter: error persistiendo preferencia de cobertura', [
                'conversation_id' => $conversation->id,
                'vehicle_id' => $vehicle->id,
                'preference' => $data['preference'],
                'error' => $e->getMessage(),
            ]);

            return $this->formatError(
                'No se pudo guardar la preferencia de cobertura. Por favor, intentá nuevamente.',
                'persistence_error'
            );
        }

        $resolved = $this->tryResolveQuoteById($quoteId);

        $message = $resolved
            ? "Preferencia '{$data['preference']}' guardada para {$vehicle->patente}. Cotización procesada; preparando las alternativas para presentártelas."
            : "Preferencia '{$data['preference']}' guardada para {$vehicle->patente}. La oferta será procesada en breve.";

        return $this->formatSuccess($message, ['vehicle_id' => $vehicle->id]);
    }

    /**
     * Devuelve una cotización con todas sus alternativas.
     *
     * @param  array  $data  Datos normalizados del payload
     */
    public function getQuote(array $data): array
    {
        $data = $this->validatePayload($data, [
            'quoteId' => 'required|integer',
        ]);

        $quote = $this->quoteService->getQuote($data['quoteId'], withAlternatives: true);

        if (! $quote) {
            return $this->formatError(
                "No se encontró una cotización con ID '{$data['quoteId']}'.",
                'missing_quote'
            );
        }

        $alternatives = $quote->alternatives->map(fn (QuoteAlternative $alt): array => [
            'quote_id' => $quote->id,
            'quote_alternative_id' => $alt->id,
            'aseguradora' => $alt->aseguradora,
            'titulo' => $alt->titulo,
            'descripcion' => $alt->descripcion,
            'normalized_grade' => $alt->normalized_grade,
            'precio' => $alt->precio,
            'moneda' => $alt->moneda,
            'marketing_title' => $alt->marketing_title,
            'sum_insured_text' => $alt->sum_insured_text,
            'features_tags' => $alt->features_tags,
            'full_details' => $alt->full_details,
        ]);

        return $this->formatSuccess(
            "Cotización #{$quote->id} obtenida. Usá quote_id={$quote->id} para el checkout.",
            ['quotes' => json_encode(['quote_id' => $quote->id, 'alternatives' => $alternatives])]
        );
    }

    /**
     * Genera un token de checkout y devuelve la URL firmada al cliente.
     *
     * @param  array  $data  Datos normalizados del payload
     * @param  Conversation  $conversation  La conversación activa
     */
    public function checkout(array $data, Conversation $conversation): array
    {
        $data = $this->validatePayload($data, [
            'quoteId' => 'required|integer',
            'quote_alternative_id' => 'required|integer',
        ]);

        $quote = Quote::where('id', $data['quoteId'])
            ->whereIn('status', ['processed', 'checkout_pending'])
            ->first();

        if (! $quote) {
            return $this->formatError(
                'No se encontró una cotización válida con ese ID para esta sesión.',
                'quote_not_found'
            );
        }

        $alternative = QuoteAlternative::where('id', $data['quote_alternative_id'])
            ->where('quote_id', $quote->id)
            ->first();

        if (! $alternative) {
            return $this->formatError(
                'La alternativa seleccionada no corresponde a la cotización indicada.',
                'alternative_not_found'
            );
        }

        $token = Str::random(10);

        $quote->update([
            'status' => 'checkout_pending',
            'checkout_token' => $token,
            'checkout_alternative_id' => $alternative->id,
        ]);

        $checkoutUrl = rtrim((string) config('app.checkout_url', config('app.url')), '/')
            .'/checkout/'.$token;

        return $this->formatSuccess(
            "Tu link de checkout está listo. Completá tus datos aquí: {$checkoutUrl}",
            ['checkout_url' => $checkoutUrl]
        );
    }

    // =========================================================================
    // MEDIA
    // =========================================================================

    /**
     * Downloads raw media content from the Meta Graph API.
     *
     * @throws \RuntimeException
     */
    public function downloadMedia(string $mediaId): string
    {
        $accessToken = config('services.whatsapp.access_token');
        $apiVersion = config('services.whatsapp.api_version', 'v21.0');
        $baseUrl = "https://graph.facebook.com/{$apiVersion}";

        $metaResponse = Http::withToken($accessToken)
            ->get("{$baseUrl}/{$mediaId}");

        if ($metaResponse->failed()) {
            throw new \RuntimeException("Failed to retrieve media metadata for ID {$mediaId}: ".$metaResponse->body());
        }

        $mediaUrl = $metaResponse->json('url');

        if (! $mediaUrl) {
            throw new \RuntimeException("No download URL returned for media ID {$mediaId}.");
        }

        $downloadResponse = Http::withToken($accessToken)->get($mediaUrl);

        if ($downloadResponse->failed()) {
            throw new \RuntimeException("Failed to download media from URL for ID {$mediaId}: ".$downloadResponse->body());
        }

        return $downloadResponse->body();
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Normaliza el payload de WhatsApp al contrato interno del adapter.
     * El wa_id del usuario se usa como external_conversation_id.
     *
     * @param  array  $payload  El payload del webhook de WhatsApp
     * @return array El payload normalizado con campos estándar
     */
    private function normalizePayload(array $payload): array
    {
        return [
            ...$payload,
            'external_conversation_id' => $payload['wa_id'] ?? $payload['external_conversation_id'] ?? null,
            'ext_user_id' => $payload['wa_id'] ?? null,
            'channel' => 'whatsapp',
            'preference' => $payload['coverage_code'] ?? $payload['preference'] ?? null,
            'quoteId' => $payload['quote_id'] ?? $payload['quoteId'] ?? null,
            'quote_alternative_id' => $payload['quote_alternative_id'] ?? $payload['alternative_id'] ?? null,
        ];
    }

    /**
     * Valida un array contra las reglas dadas y retorna solo los campos declarados.
     *
     * @throws InvalidArgumentException
     */
    private function validatePayload(array $data, array $rules): array
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new InvalidArgumentException($validator->errors()->first());
        }

        return $validator->validated();
    }

    /**
     * Intenta disparar la resolución de una quote pendiente.
     * Devuelve true si tuvo éxito, false si la quote ya no estaba pendiente o falló.
     */
    private function tryResolveQuoteById(?int $quoteId): bool
    {
        if (! $quoteId) {
            return false;
        }

        try {
            $quote = Quote::where('id', $quoteId)
                ->where('status', 'pending')
                ->first();

            if (! $quote) {
                $this->logAdapter('WhatsApp: quote ya no está pendiente, se omite resolución.', ['quote_id' => $quoteId]);

                return false;
            }

            $this->quoteService->resolveQuote($quote, $quote->riskSnapshot);

            return true;
        } catch (\Throwable $e) {
            Log::error('WhatsApp: error en resolución de quote', [
                'quote_id' => $quoteId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Construye una respuesta de éxito estándar.
     */
    private function formatSuccess(string $message, array $extra = []): array
    {
        return array_merge(['success' => true, 'tool_output' => $message], $extra);
    }

    /**
     * Construye una respuesta de error estándar.
     */
    private function formatError(string $message, string $code): array
    {
        return [
            'success' => false,
            'error' => $message,
            'error_code' => $code,
        ];
    }
}
