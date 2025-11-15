<?php
// app/Http/Controllers/ToolsController.php

namespace App\Http\Controllers;

use App\Adapters\OpenAI\AgentToolAdapter;
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

        // El adapter se encarga de todo
        $result = $this->adapter->identifyCustomer($request->all());

        $statusCode = $result['success'] ? 200 : ($result['error_code'] === 'validation_error' ? 422 : 500);

        return response()->json($result, $statusCode);
    }
}