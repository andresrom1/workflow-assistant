<?php

namespace App\Contracts;

use App\Models\Conversation;

interface AIProviderAdapterInterface
{
    /**
     * Entry point ÚNICO de las tools: recibe el payload crudo del proveedor, lo normaliza,
     * delega al tool handler correspondiente y **atrapa cualquier excepción**, dejándola
     * logueada y traducida a un error estructurado para el modelo.
     *
     * Las tools NO deben llamar a los handlers (`identifyCustomer()`, etc.) directamente:
     * saltearse este método deja las excepciones sin loguear y el SDK las convierte en un
     * texto genérico que descarta el motivo real. Ver ROADMAP, bitácora 2026-07-25.
     *
     * @param  Conversation|null  $conversation  Conversación ya resuelta por el canal. Si es null
     *                                           se cae al lookup por `external_conversation_id`
     *                                           del payload (camino web/OpenAI legacy).
     */
    public function handleToolCall(array $payload, string $toolName, ?Conversation $conversation = null): array;

    /**
     * Identifica (o crea) un cliente y lo vincula a la conversación activa.
     */
    public function identifyCustomer(array $data, Conversation $conversation): array;

    /**
     * Identifica (o crea) un vehículo, lo asocia al cliente y abre una cotización pendiente.
     */
    public function identifyVehicle(array $data, Conversation $conversation): array;

    /**
     * Persiste la preferencia de cobertura del cliente y dispara la resolución vía API.
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
