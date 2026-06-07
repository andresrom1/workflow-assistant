<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Seam de proveedor — cotización / emisión
    |--------------------------------------------------------------------------
    |
    | "mock" usa el motor determinístico de hoy (QuotingEngine / skeleton de
    | emisión); "visred" cotiza/emite contra la API real vía VisredClient. Mismo
    | criterio de seam-único que WHATSAPP_DISPATCH_DRIVER: se elige por env y el
    | bind condicional vive en AppServiceProvider (el switch llega en Fase 4).
    |
    */
    'quotation_provider' => env('QUOTATION_PROVIDER', 'mock'),
    'emission_provider' => env('EMISSION_PROVIDER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Conexión a Visred
    |--------------------------------------------------------------------------
    |
    | Base URL del sandbox verificada live (2026-06-07): endpoints en /v1/...,
    | SIN prefijo /api. `username`/`password` son las credenciales de servicio
    | del productor MANGO (identidad de servicio, no de usuario final) — viven en
    | .env, NUNCA se commitean.
    |
    */
    'base_url' => env('VISRED_BASE_URL', 'https://sandbox-api.visred.com.ar'),
    'username' => env('VISRED_USERNAME'),
    'password' => env('VISRED_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Transporte HTTP
    |--------------------------------------------------------------------------
    |
    | Timeout por request (segundos). El VisredClient suma retry transitorio
    | (red / 5xx / 429) además de este timeout.
    |
    */
    'timeout' => (int) env('VISRED_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Polling de cotización (Fase 3)
    |--------------------------------------------------------------------------
    |
    | `cotizar/` es asíncrono: devuelve una task por compañía y se hace polling
    | hasta terminal o hasta agotar el budget. Presupuesto total (segundos) e
    | intervalo entre polls. Invariante: poll_budget + overhead_LLM < timeout del
    | job de IA (ProcessConversationInbox = 180s). Ver PLAN §"Contrato de polling".
    |
    */
    'poll_budget' => (int) env('VISRED_POLL_BUDGET', 120),
    'poll_interval' => (int) env('VISRED_POLL_INTERVAL', 4),

    /*
    |--------------------------------------------------------------------------
    | Sandbox
    |--------------------------------------------------------------------------
    |
    | Habilita el header `X-Mock-Scenario` (success|error_400|error_500) para
    | forzar escenarios contra el sandbox. En PRODUCCIÓN debe quedar en false: si
    | se envía el header a la API real, responde 403. Ver docs/v2/08 §2.6.
    |
    */
    'sandbox' => (bool) env('VISRED_SANDBOX', false),
];
