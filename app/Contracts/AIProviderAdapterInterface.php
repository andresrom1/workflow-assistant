<?php

namespace App\Contracts;

interface AIProviderAdapterInterface
{
    /**
     * Define el único método que todos los adaptadores deben implementar.
     * Este método recibe el payload crudo y orquesta la lógica de dominio.
     * @param array $payload Datos del request
     * @param string $toolName
     * @return array La respuesta formateada para el proveedor de IA
     */
    public function handleToolCall(array $payload, string $toolName): array;
}