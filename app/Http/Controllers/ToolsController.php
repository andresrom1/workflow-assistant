<?php

// app/Http/Controllers/ToolsController.php

namespace App\Http\Controllers;

use App\Adapters\OpenAI\AgentToolAdapter;
// use App\Models\Vehicle;
use App\Contracts\AIProviderAdapterInterface;
use App\Factories\ToolAdapterFactory;
use App\Traits\ConditionalLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ToolsController extends Controller
{
    use ConditionalLogger;

    public function __construct(
        private readonly AgentToolAdapter $adapter,
        private readonly ToolAdapterFactory $factory
    ) {}

    /**
     * Identificar cliente
     * Este controller solo recibe HTTP y delega al adapter
     */
    public function identifyCustomer(Request $request)
    {
        Log::info('Identificacion de cliente recibido', ['request' => $request->all()]);
        // Ver TODO el request para encontrar el thread_id
        $this->logCustomer('HTTP Tool Request recibido: identify_customer', ['body' => $request->all()]);

        // Detección e Instanciación centralizada
        try {
            $adapter = $this->getAdapter($request);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Proveedor de IA no soportado'], 400);
        }

        $result = $adapter->handleToolCall($request->all(), 'identify_customer');
        $this->logCustomer('Resultado de handleToolCall', $result);

        // deberia crearse una coversacion si no existe

        return $this->jsonResponse($result);
    }

    /**
     * Identificar vehículo (Webhook) -> ¡FALTABA ESTE!
     */
    public function identifyVehicle(Request $request)
    {
        // Ver TODO el request para encontrar el thread_id
        $this->logCustomer('HTTP Tool Request recibido: identify_vehicle', ['body' => $request->all()]);
        Log::warning(__METHOD__.__LINE__.'Identificacion de vehiculo recibido', ['request' => $request->all()]);

        try {
            $adapter = $this->getAdapter($request);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Proveedor de IA no soportado'], 400);
        }

        $result = $adapter->handleToolCall($request->all(), 'identify_vehicle');
        $this->logCustomer('Resultado de handleToolCall', $result);

        // deberia crearse una coversacion si no existe

        return $this->jsonResponse($result);
    }

    public function coveragePreference(Request $request)
    {
        // Ver TODO el request para encontrar el thread_id
        $this->logCustomer('HTTP Tool Request recibido: coverage_preference', ['body' => $request->all()]);
        Log::warning(__METHOD__.__LINE__.'Identificacion de cobertura recibido', ['request' => $request->all()]);

        try {
            $adapter = $this->getAdapter($request);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Proveedor de IA no soportado'], 400);
        }

        $result = $adapter->handleToolCall($request->all(), 'coverage_preference');
        $this->logCustomer('Resultado de handleToolCall', $result);

        // deberia crearse una coversacion si no existe

        return $this->jsonResponse($result);
    }

    public function getQuote(Request $request)
    {
        // Ver TODO el request para encontrar el thread_id
        $this->logCustomer('HTTP Tool Request recibido: get_quotes', ['body' => $request->all()]);
        Log::warning(__METHOD__.__LINE__.'Obtener cotizaciones recibido', ['request' => $request->all()]);

        try {
            $adapter = $this->getAdapter($request);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Proveedor de IA no soportado'], 400);
        }

        $result = $adapter->handleToolCall($request->all(), 'get_quote');
        $this->logCustomer('Resultado de handleToolCall', $result);

        // deberia crearse una coversacion si no existe

        return $this->jsonResponse($result);
    }

    public function checkout(Request $request)
    {
        $this->logCustomer('HTTP Tool Request recibido: checkout', ['body' => $request->all()]);
        try {
            $adapter = $this->getAdapter($request);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'Proveedor de IA no soportado'], 400);
        }

        Log::info('Procesando checkout', ['request' => $request->all()]);
        $result = $adapter->handleToolCall($request->all(), 'checkout');
        $this->logCustomer('Resultado de handleToolCall checkout', $result);

        return $this->jsonResponse($result);
    }

    protected function getAdapter(Request $request): AIProviderAdapterInterface
    {
        $providerName = $request->input('ai_provider', 'openai');

        return $this->factory->make($providerName);
    }

    protected function extractOpenAIUserId(Request $request): ?string
    {
        return $request->header('X-OpenAI-User-ID')
            ?? $request->input('openai_user_id');
    }

    protected function jsonResponse(array $result): JsonResponse
    {
        $statusCode = $result['success']
            ? 200
            : ($result['error_code'] === 'validation_error' ? 422 : 500);

        return response()->json($result, $statusCode);
    }

    protected function errorResponse(string $message, int $code): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $message,
        ], $code);
    }
}
