<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait ConditionalLogger
{
    /**
     * Loguea información del RiskSnapshot si 
     *  @param string $message El mensaje principal
     * @param array $context Datos adicionales (array)
     */
    protected function logRsikSnapshot(string $message, array $context = []): void
    {
        $this->writeLog('riskSnapshot', '[RiskSnapshot]', $message, $context);
    }
    /**
     * Loguea información del ADAPTER si 'app.log.quote' es true.
     *  @param string $message El mensaje principal
     * @param array $context Datos adicionales (array)
     */
    protected function logAdapter(string $message, array $context = []): void
    {
        $this->writeLog('adapter', '[Adapter]', $message, $context);
    }
    /**
     * Loguea información del Quote si 'app.log.quote' es true.
     *  @param string $message El mensaje principal
     * @param array $context Datos adicionales (array)
     */
    protected function logQuote(string $message, array $context = []): void
    {
        $this->writeLog('quote', '[Quote]', $message, $context);
    }
    /**
     * Loguea información del CLIENTE si 'app.log.customer' es true.
     *  @param string $message El mensaje principal
     * @param array $context Datos adicionales (array)
     */
    protected function logCustomer(string $message, array $context = []): void
    {
        $this->writeLog('customer', '[Customer]', $message, $context);
    }

    /**
     * Loguea información del VEHÍCULO si 'app.log.vehicle' es true.
     * @param string $message El mensaje principal
     * @param array $context Datos adicionales (array)
     */
    protected function logVehicle(string $message, array $context = []): void
    {
        $this->writeLog('vehicle', '[Vehicle]', $message, $context);
    }

    /**
     * Loguea información de CONVERSACIÓN si 'app.log.conversation' es true.
     * @param string $message El mensaje principal
     * @param array $context Datos adicionales (array)
     */
    protected function logConversation(string $message, array $context = []): void
    {
        $this->writeLog('conversation', '[Conversation]', $message, $context);
    }

    /**
     * Loguea información de COTIZACIONES si 'app.log.quotes' es true.
     * @param string $message El mensaje principal
     * @param array $context Datos adicionales (array)
     */
    protected function logQuotes(string $message, array $context = []): void
    {
        $this->writeLog('quotes', '[Quotes]', $message, $context);
    }

    /**
     * Lógica interna para escribir el log con autodeteción de origen.
     * 
     */
    private function writeLog(string $configKey, string $prefix, string $message, array $context): void
    {
        // 1. Verificamos la configuración (app/config.php -> log -> customer)
        if (config("app.log.{$configKey}")) {
            
            // 2. Usamos debug_backtrace para saber quién llamó a este método
            // Limitamos a 3 niveles para no afectar rendimiento.
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            
            // Nivel 2: Es la clase (Adapter) que llamó al método público (logCustomer)
            $callerClass = $trace[2]['class'] ?? static::class;
            $callerFunction = $trace[2]['function'] ?? 'unknown';
            
            // Nivel 1: Es la línea exacta donde llamaste a $this->logCustomer()
            $callerLine = $trace[1]['line'] ?? 'unknown';

            // 3. Preparamos la info automática
            $metaData = [
                'origin' => "{$callerClass}::{$callerFunction}",
                'line'   => $callerLine,
            ];

            // 4. Escribimos el log combinando tu data con la metadata
            Log::info("{$prefix} {$message}", array_merge($metaData, $context));
        }
    }
}