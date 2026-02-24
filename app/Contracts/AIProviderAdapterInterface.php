<?php

namespace App\Contracts;

use App\Models\Conversation;

interface AIProviderAdapterInterface
{
    /**
     * Entry point: recibe el payload crudo del proveedor, lo normaliza
     * y delega al tool handler correspondiente.
     */
    public function handleToolCall(array $payload, string $toolName): array;

    /**
     * Identifica (o crea) un cliente y lo vincula a la conversación activa.
     */
    public function identifyCustomer(array $data, Conversation $conversation): array;

    /**
     * Identifica (o crea) un vehículo, lo asocia al cliente y abre una cotización pendiente.
     */
    public function identifyVehicle(array $data, Conversation $conversation): array;

    /**
     * Persiste la preferencia de cobertura del cliente y dispara la resolución Mobile.
     */
    public function coveragePreference(array $data, Conversation $conversation): array;

    /**
     * Devuelve una cotización con todas sus alternativas.
     */
    public function getQuote(array $data): array;

    /**
     * Genera un token de checkout y devuelve la URL al cliente.
     */
    public function checkout(array $data, Conversation $conversation): array;
}
