<?php
// app/Http/Controllers/ToolsController.php

namespace App\Http\Controllers;

use App\Adapters\OpenAI\AgentToolAdapter;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Traits\ConditionalLogger;
use App\Factories\ToolAdapterFactory;

class ToolsController extends Controller
{
    use ConditionalLogger;

    public function __construct(
        private readonly AgentToolAdapter $adapter,
        private readonly ToolAdapterFactory $factory
    ) {
    }

    /**
     * Identificar cliente
     * Este controller solo recibe HTTP y delega al adapter
     */
    public function identifyCustomer(Request $request)
    {
        Log::info('Identificacion de cliente recibido', ['request' => $request->all()]);
        // Ver TODO el request para encontrar el thread_id
        $this->logCustomer('HTTP Tool Request recibido: identify_customer', ['body' => $request->all()]);

        //Detección: El Controller pregunta "¿Quién envía esto?"
        $providerName = $request->input('ai_provider', 'openai');

        // El Factory instancia la clase correcta (AgentToolAdapter, ClaudeAdapter, etc.)
        try {
            $adapter = $this->factory->make($providerName);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Proveedor de IA no soportado'], 400);
        }

        $result = $adapter->handleToolCall($request->all(), 'identify_customer');
        $this->logCustomer('Resultado de handleToolCall', $result);

        //deberia crearse una coversacion si no existe

        return $this->jsonResponse($result);
    }
    /**
     * Identificar vehículo (Webhook) -> ¡FALTABA ESTE!
     */
    public function identifyVehicle(Request $request)
    {
        // Ver TODO el request para encontrar el thread_id
        $this->logCustomer('HTTP Tool Request recibido: identify_vehicle', ['body' => $request->all()]);
        Log::warning(__METHOD__ . __LINE__ . 'Identificacion de vehiculo recibido', ['request' => $request->all()]);
        //Detección: El Controller pregunta "¿Quién envía esto?"
        $providerName = $request->input('ai_provider', 'openai');

        // El Factory instancia la clase correcta (AgentToolAdapter, ClaudeAdapter, etc.)
        try {
            $adapter = $this->factory->make($providerName);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Proveedor de IA no soportado'], 400);
        }

        $result = $adapter->handleToolCall($request->all(), 'identify_vehicle');
        $this->logCustomer('Resultado de handleToolCall', $result);

        //deberia crearse una coversacion si no existe

        return $this->jsonResponse($result);
    }

    public function coveragePreference(Request $request)
    {
        // Ver TODO el request para encontrar el thread_id
        $this->logCustomer('HTTP Tool Request recibido: coverage_preference', ['body' => $request->all()]);
        Log::warning(__METHOD__ . __LINE__ . 'Identificacion de cobertura recibido', ['request' => $request->all()]);
        //Detección: El Controller pregunta "¿Quién envía esto?"
        $providerName = $request->input('ai_provider', 'openai');

        // El Factory instancia la clase correcta (AgentToolAdapter, ClaudeAdapter, etc.)
        try {
            $adapter = $this->factory->make($providerName);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Proveedor de IA no soportado'], 400);
        }

        $result = $adapter->handleToolCall($request->all(), 'coverage_preference');
        $this->logCustomer('Resultado de handleToolCall', $result);

        //deberia crearse una coversacion si no existe

        return $this->jsonResponse($result);
    }

    public function getQuote(Request $request)
    {
        // Ver TODO el request para encontrar el thread_id
        $this->logCustomer('HTTP Tool Request recibido: get_quotes', ['body' => $request->all()]);
        Log::warning(__METHOD__ . __LINE__ . 'Obtener cotizaciones recibido', ['request' => $request->all()]);
        //Detección: El Controller pregunta "¿Quién envía esto?"
        $providerName = $request->input('ai_provider', 'openai');

        // El Factory instancia la clase correcta (AgentToolAdapter, ClaudeAdapter, etc.)
        try {
            $adapter = $this->factory->make($providerName);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Proveedor de IA no soportado'], 400);
        }

        $result = $adapter->handleToolCall($request->all(), 'get_quotes');
        $this->logCustomer('Resultado de handleToolCall', $result);

        //deberia crearse una coversacion si no existe

        return $this->jsonResponse($result);
    }

    public function checkout(Request $request)
    {
        $this->logCustomer('HTTP Tool Request recibido: checkout', ['body' => $request->all()]);
        $providerName = $request->input('ai_provider', 'openai');

        try {
            $adapter = $this->factory->make($providerName);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Proveedor de IA no soportado'], 400);
        }

        $result = $adapter->handleToolCall($request->all(), 'checkout');
        $this->logCustomer('Resultado de handleToolCall checkout', $result);

        return $this->jsonResponse($result);
    }

    protected function extractOpenAIUserId(Request $request): ?string
    {
        return $request->header('X-OpenAI-User-ID')
            ?? $request->input('openai_user_id');
    }

    protected function jsonResponse(array $result): \Illuminate\Http\JsonResponse
    {
        $statusCode = $result['success']
            ? 200
            : ($result['error_code'] === 'validation_error' ? 422 : 500);

        return response()->json($result, $statusCode);
    }

    protected function errorResponse(string $message, int $code): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $message,
        ], $code);
    }
}