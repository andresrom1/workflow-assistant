<?php

namespace App\Adapters\AIProviders;

use App\Contracts\AIProviderAdapterInterface;
use App\Contracts\Quotability;
use App\Jobs\NotifyClientQuoteReady;
use App\Jobs\ResolveQuote;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\CoveragePreference;
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
use App\Services\Quote\QuoteComparisonService;
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
        private readonly QuoteComparisonService $comparison,
    ) {}

    /**
     * Entry point ÚNICO de las tools. Normaliza el payload, delega al handler y atrapa
     * cualquier excepción para que quede logueada con su motivo real.
     *
     * Ninguna tool debe llamar a los handlers directamente: al saltearse este try/catch la
     * excepción escapa al SDK, que la convierte en un texto genérico ("Invalid parameters
     * for tool : X") descartando el mensaje original, y el turno queda mudo en el log.
     * Ver ROADMAP, bitácora 2026-07-25.
     */
    public function handleToolCall(array $payload, string $toolName, ?Conversation $conversation = null): array
    {
        $data = $this->normalizePayload($payload);

        // Camino WhatsApp: la conversación llega inyectada en la tool (anclada en el BSUID).
        // Camino web/OpenAI legacy: se resuelve por el identificador externo del payload.
        $conversation ??= $data['external_conversation_id'] !== null
            ? $this->conversationRepo->findOrCreateByExternalId($data['external_conversation_id'], 'whatsapp')
            : null;

        $this->logAdapter("WhatsApp tool llamado: {$toolName}", [
            'payload' => $payload,
            'conversation_id' => $conversation?->id,
        ]);

        try {
            if (! $conversation instanceof Conversation && $toolName !== 'get_quote') {
                return $this->formatError(
                    "La tool {$toolName} requiere una conversación y no se pudo resolver ninguna.",
                    'missing_conversation'
                );
            }

            return match ($toolName) {
                'identify_customer' => $this->identifyCustomer($data, $conversation),
                'identify_vehicle' => $this->identifyVehicle($data, $conversation),
                'provide_vehicle_fact' => $this->provideVehicleFact($data, $conversation),
                'coverage_preference' => $this->coveragePreference($data, $conversation),
                'get_quote' => $this->getQuote($data),
                'checkout' => $this->checkout($data, $conversation),
                'revert_stage' => $this->revertStage($data, $conversation),
                default => $this->formatError("Herramienta no soportada: {$toolName}", 'tool_not_found'),
            };
        } catch (InvalidArgumentException $e) {
            Log::warning('WhatsApp Adapter: validación fallida', [
                'tool' => $toolName,
                'conversation_id' => $conversation?->id,
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);

            return $this->formatError($e->getMessage(), 'validation_error');
        } catch (\Throwable $e) {
            // \Throwable y no \Exception: un TypeError (argumento con el tipo equivocado desde
            // el modelo) es un Error, no una Exception, y con `catch (\Exception)` se escapaba
            // sin dejar rastro.
            Log::error('WhatsApp Adapter: error interno', [
                'tool' => $toolName,
                'conversation_id' => $conversation?->id,
                'payload' => $payload,
                'exception' => $e::class,
                'msg' => $e->getMessage(),
                'at' => $e->getFile().':'.$e->getLine(),
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
        // El teléfono es atributo de contacto (se captura en la ingesta), no identidad: el
        // identify del agente resuelve el "quién" por email o DNI/CUIT. La conversación (y su
        // BSUID) llega inyectada en $conversation, no en el payload.
        $data = $this->validatePayload($data, [
            'identifier_type' => 'required|string|in:email,dni',
            'identifier_value' => 'required|string',
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
            QuotabilityStatus::NeedsFact => $this->onNeedsFact($conversation, $vehicle, $quotability),
            QuotabilityStatus::NotQuotable => $this->onNotQuotable($conversation, $vehicle),
        };
    }

    /**
     * Registra un dato del vehículo que quedó pendiente para resolver la
     * quotability (p.ej. transmisión) y reintenta el gate contra el catálogo.
     *
     * @param  array{patente: string, fact: string}  $data
     */
    public function provideVehicleFact(array $data, Conversation $conversation): array
    {
        $data = $this->validatePayload($data, [
            'patente' => 'required|string',
            'fact' => 'required|string|max:100',
        ]);
        $data['patente'] = $this->plate->normalize($data['patente']);

        $conversation->load('customer.vehicles');
        $vehicle = $conversation->customer?->vehicles->where('patente', $data['patente'])->first();

        if (! $vehicle) {
            return $this->formatError(
                "No se encontró un vehículo con patente '{$data['patente']}' en esta conversación.",
                'missing_vehicle'
            );
        }

        // Enriquecer la versión (dominio) con el hecho que faltaba y reintentar el gate.
        $vehicle->version = trim($vehicle->version.' '.$data['fact']);
        $vehicle->save();

        $quotability = $this->quotability->check($vehicle);

        return match ($quotability->status) {
            QuotabilityStatus::Quotable => $this->onQuotable($conversation, $conversation->customer, $vehicle, (string) Str::uuid(), $quotability),
            QuotabilityStatus::NeedsFact => $this->onNeedsFact($conversation, $vehicle, $quotability),
            QuotabilityStatus::NotQuotable => $this->onNotQuotable($conversation, $vehicle),
        };
    }

    /**
     * Quotable: crea la cotización pendiente, persiste el token opaco del
     * proveedor (genérico, en risk_provider_refs) y dispara la consulta.
     *
     * La consulta arranca ACÁ y no al elegir la cobertura: la request a Visred no incluye la
     * preferencia de cobertura (`buildRequest()` manda vehículo, año y CP), así que no hay nada que
     * esperar. Los 30-174s de las compañías transcurren mientras el agente indaga la cobertura, en
     * vez de dejar al cliente mirando una pantalla quieta. Ver ROADMAP, bitácora 2026-08-10.
     */
    private function onQuotable(Conversation $conversation, Customer $customer, Vehicle $vehicle, string $sessionUuid, QuotabilityResult $result): array
    {
        $quote = $this->quoteService->createPendingQuote($conversation, $customer, $vehicle, $sessionUuid);

        RiskProviderRef::updateOrCreate(
            ['risk_snapshot_id' => $quote->risk_snapshot_id, 'provider' => $result->provider],
            ['external_vehicle_ref' => $result->externalRef],
        );

        $this->clearPendingVehicleFact($conversation);

        ResolveQuote::dispatch($quote->id);

        return $this->formatSuccess(
            "Vehículo registrado correctamente. Cotización #{$quote->id} iniciada y ya en consulta "
            .'con las compañías. Indagá al cliente sobre la cobertura deseada mientras tanto.',
            ['quote_id' => $quote->id]
        );
    }

    /**
     * NeedsFact: ambigüedad reencuadrada como hecho de dominio faltante. No
     * promete cotización; el agente debe preguntar el dato (p.ej. transmisión).
     * Marca el dato pendiente en la conversación para que coveragePreference()
     * sepa por qué no hay una quote en marcha si el cliente avanza antes de responder.
     */
    private function onNeedsFact(Conversation $conversation, Vehicle $vehicle, QuotabilityResult $result): array
    {
        $meta = $conversation->metadata ?? [];
        $meta['pending_vehicle_fact'] = ['patente' => $vehicle->patente, 'fact' => $result->missingFact];
        $conversation->update(['metadata' => $meta]);

        $options = $result->options !== [] ? ' ('.implode(' / ', $result->options).')' : '';

        return $this->formatSuccess(
            "Vehículo registrado. Para cotizar necesito un dato más: ¿{$result->missingFact}?{$options} "
            .'Preguntáselo al cliente antes de avanzar a la cobertura.',
            ['needs_fact' => $result->missingFact]
        );
    }

    /**
     * Limpia el marcador de dato pendiente (ya sea porque se resolvió o porque
     * se llegó a Quotable/NotQuotable por otra vía).
     */
    private function clearPendingVehicleFact(Conversation $conversation): void
    {
        $meta = $conversation->metadata ?? [];
        if (array_key_exists('pending_vehicle_fact', $meta)) {
            unset($meta['pending_vehicle_fact']);
            $conversation->update(['metadata' => $meta]);
        }
    }

    /**
     * NotQuotable: rama honesta. No mentimos identidad ("te tengo el auto"),
     * pero no prometemos una cotización que no va a llegar.
     */
    private function onNotQuotable(Conversation $conversation, Vehicle $vehicle): array
    {
        $this->clearPendingVehicleFact($conversation);

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
            // El nivel solo no alcanza: "terceros completo" y "terceros completo con granizo"
            // se guardaban idénticos. Sin estas reglas `validated()` las descarta en silencio.
            'coberturas_requeridas' => 'sometimes|array',
            'coberturas_requeridas.*' => 'string',
            'reasoning' => 'sometimes|string',
            // Un cliente puede pedir dos niveles para comparar. `preference` guarda uno solo,
            // así que sin esto el pedido se perdía a la mitad.
            'coverage_codes' => 'sometimes|array',
            'coverage_codes.*' => 'string|in:A,B,C,D',
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

        $quoteEnCurso = null;

        // Un solo nivel pedido no necesita la lista: `preference` ya lo dice.
        $niveles = array_values(array_unique($data['coverage_codes'] ?? []));

        $metadata = array_filter([
            'coberturas_requeridas' => array_values($data['coberturas_requeridas'] ?? []),
            'niveles_solicitados' => count($niveles) > 1 ? $niveles : [],
            'reasoning' => $data['reasoning'] ?? null,
        ], fn (mixed $valor): bool => $valor !== null && $valor !== []);

        try {
            DB::transaction(function () use ($conversation, $vehicle, $data, $metadata, &$quoteEnCurso): void {
                $this->coverageService->saveCoveragePreference(
                    $conversation->id,
                    $vehicle->id,
                    $data['preference'],
                    $metadata === [] ? null : $metadata
                );

                // La quote pendiente es la consulta en vuelo; si ya no hay ninguna, la vigente es
                // la que terminó mientras el agente indagaba la cobertura. La preferencia se
                // registra sobre la que corresponda: desde que la consulta se adelantó al paso
                // del vehículo, el caso normal es que ya esté resuelta.
                $quoteEnCurso = $conversation->quotes()
                    ->where('status', 'pending')
                    ->latest()
                    ->first() ?? $this->cotizacionVigenteDe($conversation);

                if ($quoteEnCurso instanceof Quote) {
                    $this->quoteService->updateSnapshotPreference($quoteEnCurso, $data['preference']);
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

        $enVuelo = $quoteEnCurso instanceof Quote && $quoteEnCurso->status === 'pending';
        $lista = $quoteEnCurso instanceof Quote && ! $enVuelo;
        $pendingFact = data_get($conversation->metadata, 'pending_vehicle_fact');

        if ($enVuelo) {
            // Todavía no volvieron las compañías: el aviso de espera es texto fijo y sale ya, no
            // al final del turno, para que el cliente sepa que arrancó.
            $this->avisarEsperaDeCotizacion($conversation, $quoteEnCurso->id);
        }

        if ($lista) {
            // Las alternativas ya están, pero CoveragePreferenceAgent no tiene `get_quote`: no
            // puede presentarlas en este turno. Este job abre uno nuevo, y para ese turno
            // `coverage_set` ya está en true, así que el orquestador entrega QuoteAgent.
            NotifyClientQuoteReady::dispatch($conversation->id, $quoteEnCurso->id)
                ->onConnection('database_ai')
                ->onQueue('whatsapp-ai');
        }

        $guardada = "Preferencia '{$data['preference']}' guardada para {$vehicle->patente}.";

        $message = match (true) {
            $enVuelo => "{$guardada} La consulta a las compañías está en marcha desde que registraste el vehículo. Al cliente ACABA de salirle un mensaje avisándole que estás consultando. Respondé SOLO confirmando la cobertura elegida, en una frase: NO menciones la consulta, NO hables de la espera y NO cierres prometiendo avisarle. Verlo dos veces seguidas se lee como que algo se rompió. Tampoco inventes alternativas ni precios — te avisan cuando lleguen.",

            // Cubre dos casos que terminan igual: la consulta terminó mientras indagabas la
            // cobertura (el normal desde que se adelantó al paso del vehículo), y el cliente que
            // pide dos niveles en un mensaje y dispara esta tool dos veces en el mismo turno.
            // Sin esto, el mensaje de abajo le hacía decir al agente que no se pudo cotizar y
            // ofrecer derivar a un humano — falso. Ver ROADMAP, bitácora 2026-08-09.
            $lista => "{$guardada} Las alternativas ya están listas y se van a presentar enseguida. NO le digas al cliente que no se pudo cotizar ni le ofrezcas derivarlo a un asesor.",

            $pendingFact !== null => "{$guardada} Falta un dato del vehículo para poder cotizar: {$pendingFact['fact']}. Preguntáselo al cliente y registralo con provide_vehicle_fact.",
            default => "{$guardada} Pero no hay una cotización en marcha para este vehículo. Explicáselo al cliente con honestidad y ofrecé derivar a un asesor.",
        };

        return $this->formatSuccess($message, ['vehicle_id' => $vehicle->id]);
    }

    /**
     * La cotización ya resuelta de la conversación, si sigue siendo usable hoy.
     *
     * Exige vigencia y no solo existencia: los precios valen por el día en que se cotizaron,
     * así que una de anteayer no habilita decirle al agente que siga presentando alternativas.
     */
    private function cotizacionVigenteDe(Conversation $conversation): ?Quote
    {
        return $conversation->quotes()
            ->where('status', 'processed')
            ->vigente()
            ->latest()
            ->first();
    }

    /**
     * Revierte la conversación a una etapa anterior (el cliente corrigió un dato
     * ya registrado) y descarta la cotización en curso, que ya no representa
     * el riesgo real.
     *
     * @param  array{stage: string}  $data
     */
    public function revertStage(array $data, Conversation $conversation): array
    {
        $data = $this->validatePayload($data, [
            'stage' => 'required|string|in:customer,vehicle,coverage',
        ]);

        $this->quoteService->expireOpenQuotes($conversation);

        return $this->formatSuccess(
            "Etapa revertida a '{$data['stage']}'. La cotización anterior quedó descartada. "
            .'Retomá la conversación pidiendo los datos de esa etapa.'
        );
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

        // El closer solo puede ofrecer lo que el checkout puede cobrar. De paso deja una sola
        // fila por producto: sin esto el mismo plan le llega repetido con el precio de cupón,
        // que es más caro, como si fueran opciones distintas.
        $ofrecibles = $quote->alternatives
            ->filter(fn (QuoteAlternative $alt): bool => $alt->esOfrecible())
            ->values();

        // El glosario va UNA vez y cada alternativa lleva solo sus tags. Antes la descripción de
        // cada cobertura viajaba adentro de cada fila: en la cotización #19 eso eran 1.588
        // entradas —33 definiciones repetidas ~48 veces— y 108.827 de los ~135.700 caracteres del
        // payload, el 80%. El vocabulario del proveedor es cerrado y cada tag tiene una única
        // descripción idéntica entre compañías, así que deduplicarlo no pierde nada.
        // Ver ROADMAP, bitácora 2026-08-13.
        $glosario = array_map(
            fn (array $entrada): string => $entrada['nota'],
            $this->comparison->glossary($ofrecibles),
        );

        $alternatives = $ofrecibles->map(fn (QuoteAlternative $alt): array => [
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
        ]);

        return $this->formatSuccess(
            "Cotización #{$quote->id} obtenida. Usá quote_id={$quote->id} para el checkout."
                .$this->recordatorioDelPedido($quote),
            ['quotes' => json_encode([
                'quote_id' => $quote->id,
                'glosario' => $glosario,
                'alternatives' => $alternatives,
            ])]
        );
    }

    /**
     * Le recuerda al closer qué pidió el cliente, en el mismo turno en que elige las alternativas.
     *
     * Sin esto tiene que re-derivarlo del historial cada vez, y cuando el cliente nombró el nivel
     * sin enumerar coberturas ("terceros completo") se quedaba con la más barata del nivel — que
     * es justo la que no trae granizo ni cristales.
     */
    private function recordatorioDelPedido(Quote $quote): string
    {
        $preferencia = CoveragePreference::query()
            ->where('conversation_id', $quote->conversation_id)
            ->latest('updated_at')
            ->first();

        if (! $preferencia instanceof CoveragePreference) {
            return '';
        }

        $niveles = $preferencia->metadata['niveles_solicitados'] ?? [];

        $recordatorio = is_array($niveles) && count($niveles) > 1
            ? ' El cliente pidió comparar las coberturas '.implode(' y ', $niveles).'. Presentá una de cada nivel.'
            : " El cliente pidió cobertura {$preferencia->preference}.";

        $requeridas = $preferencia->metadata['coberturas_requeridas'] ?? [];

        if (is_array($requeridas) && $requeridas !== []) {
            $recordatorio .= ' Coberturas requeridas: '.implode(', ', $requeridas)
                .'. Descartá las alternativas que no las incluyan, por más baratas que sean.';
        }

        return $recordatorio;
    }

    /**
     * Genera un token de checkout y devuelve la URL firmada al cliente.
     *
     * La creación vive en QuoteService: la comparten este adapter, el path OpenAI y el CTA de
     * la vista pública. Acá queda solo la traducción al canal.
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

        $resultado = $this->quoteService->crearCheckout(
            (int) $data['quoteId'],
            (int) $data['quote_alternative_id']
        );

        if (! $resultado['ok']) {
            return $this->formatError($resultado['error'], $resultado['error_code']);
        }

        return $this->formatSuccess(
            "Tu link de checkout está listo. Completá tus datos aquí: {$resultado['url']}",
            ['checkout_url' => $resultado['url']]
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
     * Le avisa al cliente que la consulta a las compañías arranca, antes de que arranque.
     *
     * Texto fijo y despacho directo a la cola de salida: hacerlo pasar por el LLM implicaría
     * esperar a que termine el turno, y el turno incluye la consulta sincrónica de 25-60s —
     * o sea que el anuncio llegaría al final de la espera que anuncia.
     *
     * Best-effort: sin destinatario se loguea y la cotización sigue su curso igual.
     */
    private function avisarEsperaDeCotizacion(Conversation $conversation, ?int $quoteId): void
    {
        // Sin quote pendiente no hay consulta que anunciar.
        if ($quoteId === null) {
            return;
        }

        $bsuid = $conversation->ext_user_id;
        $phone = $conversation->recipientPhone();
        $phoneNumberId = config('services.whatsapp.phone_number_id');

        if ((! $phone && ! $bsuid) || ! is_string($phoneNumberId) || $phoneNumberId === '') {
            Log::warning('WhatsApp: sin destinatario para el aviso de espera de cotización', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        SendWhatsAppMessage::dispatch(
            $phone,
            $bsuid,
            (string) config('whatsapp.quote_wait_notice'),
            $phoneNumberId,
            $conversation->id,
            'quote_wait_notice',
        )->onQueue('whatsapp-outbound');
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
