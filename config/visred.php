<?php

return [
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
    | Monto asegurado del equipo de GNC (cotización)
    |--------------------------------------------------------------------------
    |
    | Visred EXIGE `insured_amount_fuel` cuando `fuel_type_id="gnc"` (400 si falta:
    | "Debe indicar el monto asegurado del equipo de GNC."). El valor del equipo no
    | se captura aún en cotización (el checkout solo toma `has_gnc` booleano) → se
    | manda este default y se refina en emisión. Ver docs/v2/08 §2.2.
    |
    */
    'default_gnc_amount' => (int) env('VISRED_DEFAULT_GNC_AMOUNT', 1_500_000),

    /*
    |--------------------------------------------------------------------------
    | DNI placeholder del titular (cotización)
    |--------------------------------------------------------------------------
    |
    | El DNI real se captura recién en checkout, así que en cotización mandamos SIEMPRE
    | este placeholder y se sobrescribe con el DNI real al EMITIR. Decisión: cotizar con
    | DNI placeholder + emitir con el real. Cotización en dos fases RECHAZADA (una vez en
    | checkout no se vuelve a cotizar). Ver docs/v2/08 §2.2.
    |
    | El placeholder FUNCIONA (San Cristóbal cotizó con 30000000 en test manual al
    | sandbox). Galicia/San Cristóbal fallan INTERMITENTEMENTE por
    | `external_service_unavailable` (503: backend de la compañía caído en sandbox), NO
    | por el DNI ni por nuestro request — verificado live 2026-06-10 reproduciendo el
    | request exacto. ⚠️ Consulta a Visred: ¿es limitación del sandbox o también en
    | prod? ¿la prima depende del DNI? Ver docs/v2/08 §2.2.
    |
    */
    'default_holder_dni' => env('VISRED_DEFAULT_HOLDER_DNI', '30123727'),

    /*
    |--------------------------------------------------------------------------
    | Mapa de fotos de inspección (Fase 5 — Pieza E)
    |--------------------------------------------------------------------------
    |
    | Traduce los `photo_key` del checkout (mango) → `inspection_type_id` de
    | Visred. Los ids del lado derecho están VERIFICADOS contra el sandbox
    | (GET /params/inspection-types/, 6 compañías, 2026-06-07): los 4 cardinales
    | aparecen en todas; `cedula`/`auxilio`/`parabrisas` en varias. La compañía
    | declara qué tipos exige (endpoint en vivo); el adapter envía los que tiene
    | foto y loggea los requeridos sin captura (p.ej. `velocimetro`, `tubo-gnc`,
    | `oblea-gnc` que el checkout no toma hoy = gap de captura). Es mapeo de
    | catálogo del proveedor → vive en config del adapter, no en el dominio.
    |
    */
    'inspection_photo_map' => [
        'tarjeta_verde' => 'cedula',
        'frente' => 'foto-frontal',
        'atras' => 'foto-atras',
        'lateral_i' => 'costado-izquierdo',
        'lateral_d' => 'costado-derecho',
        'auxilio' => 'auxilio',
        'parabrisas' => 'parabrisas',
        // D2: el velocímetro siempre se captura; los de GNC solo si el vehículo
        // tiene equipo (el form los condiciona a `has_gnc`). Los `inspection_type_id`
        // del lado derecho salen de la verificación live (6 compañías, 2026-06-07);
        // re-confirmar contra el sandbox al cerrar D6.
        'velocimetro' => 'velocimetro',
        'tubo_gnc' => 'tubo-gnc',
        'oblea_gnc' => 'oblea-gnc',
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentos oficiales a capturar al emitir
    |--------------------------------------------------------------------------
    |
    | Mapa `task_type_id` (Visred) → `kind` (dominio) de los documentos que el
    | adapter descarga dentro de `emit()` (con el `presale_id` vivo) y persiste en
    | R2. El catálogo de task-type es global (no por póliza); que una compañía/
    | producto no tenga uno disponible se maneja best-effort (se loggea, no rompe).
    | `download-poliza` es el documento base; sumar `download-cupon`/`-certificate`/
    | etc. acá cuando producto lo pida. Ver hallazgos-visred-task-type.md.
    |
    */
    'document_task_types' => [
        'download-poliza' => 'poliza',
    ],

    /*
    |--------------------------------------------------------------------------
    | Catálogo de condiciones fiscales (D1 — titular)
    |--------------------------------------------------------------------------
    |
    | `tax_condition_id` del holder sale de este catálogo de params. El checkout
    | lo ofrece como select (lo trae `VisredCatalogService::taxConditions()`,
    | cacheado). Ruta y shape VERIFICADOS live (D6, 2026-06-08): `{id, description}`
    | (CF/EX/RMT/no_responsable/no_categorizado/RI). Params en singular y top-level.
    |
    */
    'tax_conditions_path' => env('VISRED_TAX_CONDITIONS_PATH', '/v1/params/tax-condition/'),
    'tax_conditions_ttl' => (int) env('VISRED_TAX_CONDITIONS_TTL', 86400),

    /*
    |--------------------------------------------------------------------------
    | Tope de descuento del productor, POR COMPAÑÍA (D8)
    |--------------------------------------------------------------------------
    |
    | El máximo descuento que el productor puede otorgar es POR COMPAÑÍA y Visred NO
    | lo expone (el catálogo `/params/discount/` incluso lista % por encima del tope:
    | Triunfo cataloga hasta 30% pero emitir con >20% da 400). En sandbox solo Triunfo
    | devuelve catálogo; el resto `[]` (sin descuentos → no se aplica nada).
    | `MaxDiscountPolicy` elige el mayor descuento ≤ tope de ESA compañía.
    |
    | Mapa `company_id → %` + `default` (fallback). Default 0 = sin bonificar (siempre
    | válido). ⚠️ Los topes deberían CONFIRMARSE con Visred (no hay endpoint que los dé).
    |
    */
    'max_discount_percent' => [
        'default' => (float) env('VISRED_MAX_DISCOUNT_PERCENT', 0),
        'triunfo' => 20.0, // tope verificado live (emitir con >20% → 400)
    ],

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
