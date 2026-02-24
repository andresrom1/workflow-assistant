<?php

namespace App\Adapters\OpenAI;

use App\Contracts\AIProviderAdapterInterface;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\Vehicle;
use App\Repositories\ConversationRepository;
use App\Services\CoveragePreferenceService;
use App\Services\CustomerIdentificationService;
use App\Services\PlateNormalizerService;
use App\Services\QuoteService;
use App\Services\VehicleIdentificationService;
use App\Traits\ConditionalLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class AgentToolAdapter implements AIProviderAdapterInterface
{
    use ConditionalLogger;

    public function __construct(
        private readonly CustomerIdentificationService $customerService,
        private readonly VehicleIdentificationService $vehicleService,
        private readonly ConversationRepository $conversationRepo,
        private readonly QuoteService $quoteService,
        private readonly CoveragePreferenceService $coverageService,
        private readonly PlateNormalizerService $plate,
    ) {}

    /**
     * Implementación obligatoria del método handleToolCall (ENTRY POINT).
     * Este método recibe el payload y delega la ejecución al método de herramienta correspondiente.
     *
     * @param  array  $payload  Datos del request HTTP (body)
     * @param  string  $toolName  Nombre de la herramienta a ejecutar
     * @return array La respuesta formateada para el proveedor de IA
     */
    public function handleToolCall(array $payload, string $toolName): array
    {
        Log::warning(__METHOD__.__LINE__.'handleToolCall invocado para la herramienta: '.$toolName, ['payload' => $payload]);
        $this->logAdapter("handleToolCall invocado para la herramienta: {$toolName}", ['payload' => $payload]);

        // 1- Validar que el request tiene todos los datos necesarios
        Validator::make($payload, [
            'sessionUuid' => 'required|string',
            'thread_id' => 'required|string',
            'openai_user_id' => 'required|string',
            'channel' => 'nullable|string|in:web,whatsapp,telegram',
        ])->validate();

        // Transformaciones necesarias para continuar agnostico
        $data = $payload;
        $data['external_conversation_id'] = $data['thread_id'];
        $data['external_user_id'] = $data['openai_user_id'];
        unset($data['thread_id']);
        unset($data['openai_user_id']);
        unset($data['ai_provider']);

        if (! empty($data['coverage_code'])) {
            $data['preference'] = $data['coverage_code'];
            unset($data['coverage_code']);
            // En el servicio de preferencia de cobertura se validara que el valor exista
        }

        $channel = $payload['channel'] ?? 'web';
        $data['sessionUuid'] = $payload['sessionUuid'];

        if (! empty($payload['quotation_number'])) {
            $data['quoteId'] = $payload['quotation_number'];
        }

        Log::warning(__METHOD__.__LINE__.'Buscando o creando conversación externa', [
            '$data' => $data,
        ]);

        $conversation = $this->conversationRepo
            ->findOrCreateByExternalId($data['external_conversation_id'], $channel);

        $this->logAdapter(
            "HTTP Tool Request recibido: {$toolName}",
            ['payload' => $payload, 'conversation_id' => $conversation->id]
        );

        try {
            return match ($toolName) {
                'identify_customer' => $this->identifyCustomer($data, $conversation),
                'identify_vehicle' => $this->identifyVehicle($data, $conversation),
                'coverage_preference' => $this->coveragePreference($data, $conversation),
                'get_quote' => $this->getQuote($data, true),
                'checkout' => $this->checkout($data, $conversation),
                default => $this->formatError("Herramienta no soportada: {$toolName}", 'tool_not_found'),
            };
        } catch (InvalidArgumentException $e) {
            Log::warning('Validación fallida en Adapter', ['error' => $e->getMessage()]);

            return $this->formatError($e->getMessage(), 'validation_error');
        } catch (\Exception $e) {
            Log::error('Server Error en Adapter', ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return $this->formatError('Error interno del servidor', 'server_error');
        }
    }

    /**
     * Identifica cliente y lo vincula a la conversación actual.
     *
     * @param  Conversation  $conversation
     *                                      Retorna ARRAY para la IA (No el objeto Customer).
     */
    protected function identifyCustomer(array $payload, Conversation $conversation): array
    {
        $this->logCustomer('Adapter: Iniciando identificación de cliente', $payload);

        // 1. Validamos y Obtenemos el Cliente (Si falla, deja que la excepción suba)
        // No uses try/catch aquí si vas a silenciar el error.
        // Deja que handleToolCall capture la excepción y devuelva el error formateado.

        $data = $this->validateCustomer($payload); // Usamos el método validador privado

        $customer = $this->customerService->findOrCreate(
            $data['identifier_type'],
            $data['identifier_value']
        );

        $this->logCustomer('Adapter: Cliente obtenido del servicio', ['id' => $customer->id]);

        // 2. ORQUESTACIÓN Y VINCULACIÓN
        // Lógica: Siempre vinculamos el cliente actual a la conversación.
        // Esto cubre:
        // A. Conversación nueva (Anónimo -> Identificado)
        // B. Corrección de error (Dueño A -> Dueño B)

        $currentOwnerId = $conversation->customer_id;

        // Solo auditamos si hubo un cambio real de dueño
        if ($currentOwnerId && $currentOwnerId !== $customer->id) {
            $this->logCustomer('Cambio de titularidad en conversación', [
                'conversation_id' => $conversation->id,
                'from' => $currentOwnerId,
                'to' => $customer->id,
            ]);
        }

        // Ejecutamos la vinculación (Si ya es el mismo, el update es barato o el repo lo maneja)
        $this->conversationRepo->linkCustomer($conversation->id, $customer->id);

        // 3. CONSTRUCCIÓN DE MEMORIA (Opcional, si usas la lógica de vehículos/quotes)
        // ... (Aquí iría la lógica de buildCustomerHistoryContext) ...

        // 4. RETORNO BLINDADO (Array para la IA)
        return [
            'success' => true,
            'tool_output' => 'Cliente identificado correctamente',
        ];
    }

    protected function identifyVehicle(array $data, Conversation $conversation): array
    {
        Log::error('DEBUG identifyVehicle data keys', [
            'keys' => array_keys($data),
            'data' => $data,
        ]);

        $sessionUuid = $data['sessionUuid'];

        // 1. Cargar el Customer (Garantizado por el flujo)
        // Usamos la relación para cargar el modelo Customer.
        /** @var Customer $customer */
        $customer = $conversation->customer;

        if (! $customer) {
            // Defensa en profundidad: aunque el flujo lo garantice, el código se protege.
            return $this->formatError('No se ha identificado un cliente para asignar el vehículo.', 'missing_customer');
        }

        $this->logAdapter('Adapter: Iniciando identificación de vehículo', [
            'data' => $data,
        ]);
        // 2. Validar Payload (Patente, Marca, etc.)
        $data = $this->validateVehicle($data);

        // 3. Servicio PURO (Find or Create, con lógica de transferencia de propiedad)
        /** @var Vehicle $vehicle */
        $vehicle = $this->vehicleService->findOrCreate($customer, $data);

        // 4. Lógica de Actualización / Transferencia (Decisión del Orquestador)
        // Si el vehículo ya existía (no es nuevo), aprovechamos para actualizar datos permitidos
        // (como CP o Versión) y transferir la propiedad si el dueño era otro.
        if ($vehicle->wasRecentlyCreated === false) {
            // El servicio updateVehicle maneja la lógica restrictiva de qué campos tocar
            $this->vehicleService->updateVehicle($vehicle, $customer, $data);
            $this->logAdapter('Adapter: Datos de vehículo actualizados/transferidos.', ['patente' => $vehicle->patente]);
        }

        // ORQUESTACIÓN CRÍTICA: Iniciar Cotización (Estado Pending)
        // Se crea aquí para que el Timer de Fallback empiece a correr, pero no se envía a PAS aún.
        $quote = $this->quoteService->createPendingQuote($conversation, $customer, $vehicle, $sessionUuid);

        $this->logAdapter('Adapter: Cotización pendiente creada.', ['quote_id' => $quote->id]);

        // RETORNO BLINDADO A LA IA (UX Conversacional)
        return [
            'success' => true,
            'tool_output' => "Vehículo registrado correctamente. Se ha iniciado el proceso de cotización #{$quote->id}. Por favor, indaga al cliente sobre la cobertura deseada para proceder con la oferta a los productores.",
            'quote_id' => $quote->id,
        ];
    }

    /** Guarda la preferencia de cobertura del cliente para un determinado vehiculo.
     */
    // public function coveragePreference(array $data, Conversation $conversation): array
    // {
    //     $this->logAdapter('Adapter: Iniciando identificación de cobertura', ['data' => $data]);
    //     Log::info('Adapter: Iniciando identificación de cobertura', ['data' => $data]);

    //     // 1. Validar request
    //     $data = $this->validateCoveragePreference($data);
    //     $data['patente'] = $this->plate->normalize($data['patente']);

    //     // 2. Identificar el vehículo al que se le asignara la preferencia.

    //     $conversation->load('customer.vehicles');

    //     $vehicle = $conversation->customer->vehicles
    //         ->where('patente', $data['patente'])->first();

    //     if (!$vehicle) {
    //         return $this->formatError("No se encontró un vehículo con patente '{$data['patente']}' en esta conversación.", "missing_vehicle");
    //     }

    //     // 3. Persistir preferencia
    //     $this->coverageService->saveCoveragePreference(
    //         $conversation->id,
    //         $vehicle->id,
    //         $data['preference']
    //     );

    //     // 4. ORQUESTACIÓN CRÍTICA: Disparar Resolución Prioritaria (Mobile)
    //     // Buscamos la quote pendiente creada en el paso identify_vehicle.
    //     $quote = $conversation->quotes()->where('status', 'pending')->latest()->first();

    //     if ($quote) {

    //         // Sincronizamos la preferencia con el Snapshot para que el PAS la vea
    //         $this->quoteService->updateSnapshotPreference($quote, $data['preference']);

    //         // Disparamos la resolución (Mobile por prioridad)
    //         // Refrescamos la relación para asegurar que resolve() use el snapshot actualizado
    //         $this->quoteService->resolveQuote($quote, $quote->refresh()->riskSnapshot, 'mobile');
    //     }

    //     return [
    //         'success' => true,
    //         'tool_output' => "Preferencia de cobertura '{$data['preference']}' guardada. La oferta ha sido enviada a los productores para el vehículo {$vehicle->patente}.",
    //         'vehicle_id' => $vehicle->id,
    //         //'quote_id' => $quote?->id,
    //     ];
    // }

    public function coveragePreference(array $data, Conversation $conversation): array
    {
        $this->logAdapter('CoveragePreference: Iniciando', ['data' => $data]);

        // 1. Validar y normalizar input
        $data = $this->validateCoveragePreference($data);
        $data['patente'] = $this->plate->normalize($data['patente']);

        // 2. Identificar vehículo
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

        // 3. Persistir preferencia y sincronizar snapshot (unidad atómica)
        $quoteId = null;

        try {
            DB::transaction(function () use ($conversation, $vehicle, $data, &$quoteId) {
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
            Log::error('CoveragePreference: Error persistiendo preferencia', [
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

        // 4. Disparar resolución Mobile (fuera de la transacción, efecto externo)
        $resolved = $this->tryResolveQuoteById($quoteId, $data['preference']);

        // 5. Respuesta honesta según lo que realmente ocurrió
        $this->logAdapter('CoveragePreference: Finalizado', [
            'vehicle_id' => $vehicle->id,
            'preference' => $data['preference'],
            'resolved' => $resolved,
        ]);

        return [
            'success' => true,
            'tool_output' => $this->buildToolOutput($vehicle->patente, $data['preference'], $resolved),
            'vehicle_id' => $vehicle->id,
        ];
    }

    /**
     * Intenta disparar la resolución Mobile para una quote específica por ID.
     * Busca la quote fresca desde la DB validando que siga en estado pending.
     * Devuelve true si fue exitosa, false si no había quote, ya no estaba pendiente, o falló.
     */
    private function tryResolveQuoteById(?int $quoteId, string $preference): bool
    {
        if (! $quoteId) {
            $this->logAdapter('CoveragePreference: No hay quote pendiente, se omite resolución.');

            return false;
        }

        try {
            $quote = Quote::where('id', $quoteId)
                ->where('status', 'pending')
                ->first();

            if (! $quote) {
                $this->logAdapter('CoveragePreference: Quote ya no está pendiente, se omite resolución.', [
                    'quote_id' => $quoteId,
                ]);

                return false;
            }

            $this->logAdapter('CoveragePreference: Disparando resolución Mobile', [
                'quote_id' => $quote->id,
                'preference' => $preference,
            ]);

            $this->quoteService->resolveQuote($quote, $quote->riskSnapshot, 'mobile');

            return true;

        } catch (\Throwable $e) {
            Log::error('CoveragePreference: Error en resolución Mobile', [
                'quote_id' => $quoteId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Construye el mensaje de tool_output según el resultado real del proceso.
     */
    private function buildToolOutput(string $patente, string $preference, bool $resolved): string
    {
        if ($resolved) {
            return "Preferencia de cobertura '{$preference}' guardada para el vehículo {$patente}. "
                .'La oferta fue enviada a los productores.';
        }

        return "Preferencia de cobertura '{$preference}' guardada para el vehículo {$patente}. "
            .'La oferta será procesada en breve.';
    }

    private function getQuote(array $data, bool $withAlternatives = false): array
    {
        $quoteId = $data['quoteId'];
        Log::info('Adapter: Obteniendo cotización', ['quote_id' => $quoteId]);
        $quote = $this->quoteService->getQuote($quoteId, $withAlternatives);
        if (! $quote) {
            return $this->formatError("No se encontró una cotización con ID '{$quoteId}'.", 'missing_quote');
        }

        // Usamos directamente la relación Eloquent: cada QuoteAlternative ya tiene su id
        // de DB (que es el quote_alternative_id) y todos los campos del raw_response.
        // Esto es más simple y robusto que hacer un join sobre raw_response.
        $alternatives = $quote->alternatives->map(fn ($alt) => [
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
            // external_code y external_quote_id excluidos intencionalmente — scope de proveedor (ver quote_provider_refs)
        ]);

        return [
            'success' => true,
            'tool_output' => "Cotización #{$quote->id} obtenida correctamente. Usá quote_id={$quote->id} para el checkout.",
            'quotes' => json_encode([
                'quote_id' => $quote->id,
                'alternatives' => $alternatives,
            ]),
        ];
    }

    /**
     * Valida y sanea los datos de la preferencia de cobertura.
     *
     * @throws \InvalidArgumentException Si la validación falla
     */
    private function validateCoveragePreference(array $data): array
    {
        $rules = [
            'patente' => 'required|string',
            'preference' => 'required|string',
        ];

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new InvalidArgumentException(
                'Error de validación en validateCoveragePreference: '.$validator->errors()->first()
            );
        }

        return $validator->validated();
    }

    /**
     * Valida y sanea los argumentos del vehículo.
     *
     * @return array Datos validados y limpios
     *
     * @throws \InvalidArgumentException Si la validación falla
     */
    private function validateVehicle(array $arguments): array
    {
        $rules = [
            'patente' => [
                'required',
                'string',
                'regex:/^([A-Z]{3}\s?\d{3}|[A-Z]{2}\s?\d{3}\s?[A-Z]{2})$/i',
            ],
            'marca' => 'required|string|max:100',
            'modelo' => 'required|string|max:100',
            'version' => 'required|string|max:100',
            'year' => 'required|integer|min:1900|max:'.(date('Y') + 1),
            'combustible' => 'required|string|in:nafta,Nafta,diesel,Diesel,gnc,GNC,electrico,Electrico,hibrido,Hibrido',
            'codigo_postal' => 'required|string|max:10',
        ];

        $validator = Validator::make($arguments, $rules);

        if ($validator->fails()) {
            throw new InvalidArgumentException(
                'Error de validación en identifyVehicle: '.$validator->errors()->first()
            );
        }

        return $validator->validated();
    }

    /**
     * Valida y sanea el payload de entrada.
     *
     * @return array Datos validados y limpios
     *
     * @throws \InvalidArgumentException Si la validación falla
     */
    private function validateCustomer(array $payload): array
    {
        // Definimos reglas robustas
        $validator = Validator::make($payload, [
            'identifier_type' => 'required|string|in:email,phone,wbid', // Ejemplo: restringir valores
            'identifier_value' => 'required|string',
            'external_conversation_id' => 'required|string',
            'ai_provider' => 'nullable|string',
            'external_user_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            // Lanzamos tu excepción preferida con el mensaje del primer error encontrado
            $this->logCustomer('Validación fallida en AgentToolAdapter', ['errors' => $validator->errors()->all(), 'payload' => $payload]);
            throw new InvalidArgumentException(
                'Error de validación en AgentToolAdapter: '.$validator->errors()->first()
            );
        }

        // Retorna SOLO los campos definidos en las reglas (seguridad)
        return $validator->validated();
    }

    /**
     * Genera un link de checkout firmado para que el usuario complete sus datos,
     * fotos de inspección y datos de tarjeta de crédito.
     *
     * @param  array  $data  Payload transformado (incluye quoteId y quote_alternative_id)
     * @param  Conversation  $conversation  Conversación activa
     * @return array Respuesta para la IA con checkout_url
     */
    protected function checkout(array $data, Conversation $conversation): array
    {
        $this->logAdapter('Adapter: Iniciando checkout', ['data' => $data]);
        Log::info('Adapter: Iniciando checkout', ['data' => $data]);

        // Validación básica de entrada (quoteId y quote_alternative_id)
        if (empty($data['quoteId']) && empty($data['quote_id'])) {
            return $this->formatError(
                'Se requiere quoteId para iniciar el checkout.',
                'missing_quote_id'
            );
        }
        // Aceptar quote_id directo o el valor ya mapeado como quoteId
        $quoteId = $data['quoteId'] ?? $data['quote_id'] ?? null;
        $alternativeId = $data['quote_alternative_id'] ?? $data['alternative_id'] ?? null;
        Log::info('Checkout: quoteId y alternativeId extraídos', ['quoteId' => $quoteId, 'alternativeId' => $alternativeId]);
        if (! $quoteId || ! $alternativeId) {
            return $this->formatError(
                'Se requieren quote_id y quote_alternative_id para iniciar el checkout.',
                'missing_params'
            );
        }

        // Verificar que el quote pertenece a esta conversación
        $quote = Quote::where('id', $quoteId)
            // ->where('conversation_id', $conversation->id)
            ->whereIn('status', ['processed', 'offered_pas', 'checkout_pending']) // Aceptamos también checkout_pending para permitir reintentos
            ->first();

        Log::info('Checkout: Quote verificado', ['quote' => $quote]);
        if (! $quote) {
            return $this->formatError(
                'No se encontró una cotización válida con ese ID para esta sesión.',
                'quote_not_found'
            );
        }

        // Verificar que la alternativa pertenece al quote
        $alternative = QuoteAlternative::where('id', $alternativeId)
            ->where('quote_id', $quote->id)
            ->first();

        if (! $alternative) {
            return $this->formatError(
                'La alternativa seleccionada no corresponde a la cotización indicada.',
                'alternative_not_found'
            );
        }

        // Generar token opaco único y guardarlo en el quote junto con la alternativa
        $token = \Illuminate\Support\Str::random(10); // 10 chars alfanuméricos, URL-safe

        $quote->update([
            'status' => 'checkout_pending',
            'checkout_token' => $token,
            'checkout_alternative_id' => $alternative->id,
        ]);

        // URL limpia: sin query params, sin firma
        $checkoutUrl = route('checkout.show', ['token' => $token]);

        $this->logAdapter('Adapter: Checkout URL generada', [
            'quote_id' => $quote->id,
            'alternative_id' => $alternative->id,
        ]);

        return [
            'success' => true,
            'checkout_url' => $checkoutUrl,
            'tool_output' => "Tu link de checkout está listo. Completá tus datos de contratación aquí: {$checkoutUrl}",
        ];
    }

    private function formatError(string $msg, string $code): array
    {
        return [
            'success' => false,
            'error' => $msg,
            'error_code' => $code,
        ];
    }
}
