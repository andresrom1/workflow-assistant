<?php
// app/Http/Controllers/ToolsController.php

namespace App\Http\Controllers;

use App\Adapters\OpenAI\AgentToolAdapter;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ToolsController extends Controller
{
    public function __construct(
        private readonly AgentToolAdapter $adapter,
    ) {}

    /**
     * Identificar cliente
     * Este controller solo recibe HTTP y delega al adapter
     */
    public function identifyCustomer(Request $request)
    {
        Log::info('HTTP Request recibido: identify_customer', $request->all());
        
            // DEBUG: Ver TODO el request para encontrar el thread_id
        Log::info(__METHOD__ . __LINE__ . ' HTTP Tool Request recibido: identify_customer', [
            'body' => $request->all(),
            'headers' => $request->headers->all(),
            'raw_body' => $request->getContent(),
        ]);

        // El adapter se encarga de todo
        $result = $this->adapter->identifyCustomer($request->all());

        //$statusCode = $result['success'] ? 200 : ($result['error_code'] === 'validation_error' ? 422 : 500);

        return $this->jsonResponse($result);
        //return response()->json($result, $statusCode);
    }

    public function identifyVehicle(Request $request)
    {
        $openaiUserId = $this->extractOpenAIUserId($request);

        if (!$openaiUserId) {
            return $this->errorResponse('OpenAI User ID is required', 400);
        }

        Log::info('identify_vehicle called', [
            'openai_user_id' => $openaiUserId,
            'body' => $request->all(),
        ]);

        $result = $this->adapter->identifyVehicle(
            $request->all(),
            $openaiUserId
        );

        // $statusCode = $result['success'] ? 200 : ($result['error_code'] === 'validation_error' ? 422 : 500);

        // return response()->json($result, $statusCode);
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