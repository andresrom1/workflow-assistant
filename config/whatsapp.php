<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Driver de dispatch
    |--------------------------------------------------------------------------
    |
    | "cloud" envía de verdad por la WhatsApp Business Cloud API (encola un Job).
    | "log" no envía: solo loguea el destinatario + template (default en local/
    | testing sin credenciales). Mismo criterio de seam-único del des-mockeo.
    |
    */
    'dispatch_driver' => env('WHATSAPP_DISPATCH_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Ventana de silencio del inbox (debounce deslizante)
    |--------------------------------------------------------------------------
    |
    | Segundos de silencio que ProcessConversationInbox espera antes de llamar
    | al AI. Es DESLIZANTE: cada mensaje nuevo corre la ventana, así que la
    | seguidilla se agrupa en una sola llamada. El valor también se usa como
    | delay inicial del despacho.
    |
    | Es corto a propósito. La versión anterior era una ventana FIJA de 8s desde
    | cada mensaje — le cobraba esa espera al 100% de los turnos (p50 de LLM:
    | 4,5s, o sea que la espera era el doble que el trabajo) y aun así partía las
    | seguidillas, porque no esperaba a que el cliente parara de escribir. Los
    | mensajes que llegan una vez arrancada la generación los agrupa la
    | intercepción en el envío, no esta ventana.
    |
    */
    'inbox_quiet_seconds' => (int) env('WHATSAPP_INBOX_QUIET_SECONDS', 3),

    /*
    |--------------------------------------------------------------------------
    | Tope duro de espera del inbox
    |--------------------------------------------------------------------------
    |
    | Segundos máximos que un mensaje puede quedar sin procesar por culpa de la
    | ventana deslizante. Sin este tope, alguien que escribe sin parar difiere el
    | turno indefinidamente y el bot nunca contesta.
    |
    */
    'inbox_max_wait_seconds' => (int) env('WHATSAPP_INBOX_MAX_WAIT_SECONDS', 15),

    /*
    |--------------------------------------------------------------------------
    | Intercepciones máximas por turno
    |--------------------------------------------------------------------------
    |
    | Cuántas veces se puede descartar una respuesta ya generada porque llegaron
    | mensajes nuevos mientras el LLM pensaba. Alcanzado el tope se envía lo que
    | haya: si no, un cliente que escribe sin parar encadena turnos para siempre.
    |
    */
    'inbox_max_intercepts' => (int) env('WHATSAPP_INBOX_MAX_INTERCEPTS', 2),

    /*
    |--------------------------------------------------------------------------
    | Typing indicator
    |--------------------------------------------------------------------------
    |
    | Muestra "escribiendo…" al cliente mientras el turno se procesa. Se ancla al
    | wamid del mensaje entrante y de paso lo marca como leído (tildes azules).
    | Meta lo sostiene 25s como máximo, o hasta que mandemos la respuesta.
    |
    */
    'typing_indicator_enabled' => (bool) env('WHATSAPP_TYPING_INDICATOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Número público para wa.me (landing / marketing)
    |--------------------------------------------------------------------------
    |
    | Número del asistente IA en formato internacional sin "+" (ej: 549351XXXXXXX).
    | La landing arma con esto el link https://wa.me/{n}. Si es null, el CTA de
    | cotización queda como placeholder (href="#").
    |
    */
    'public_number' => env('WHATSAPP_PUBLIC_NUMBER'),

    /*
    |--------------------------------------------------------------------------
    | Link de descarga de la app MANGO
    |--------------------------------------------------------------------------
    |
    | URL a la store / APK de mango-mobile para el CTA de la landing.
    | Si es null, el botón queda como placeholder (href="#").
    |
    */
    'app_download_url' => env('MANGO_APP_DOWNLOAD_URL'),

    /*
    |--------------------------------------------------------------------------
    | Message Templates (aprobados por Meta — los crea el equipo de MANGO)
    |--------------------------------------------------------------------------
    |
    | Avisos iniciados por el negocio fuera de la ventana de 24h: requieren
    | templates pre-aprobados. El código los referencia por nombre/idioma; los
    | nombres reales se setean por env cuando Meta los apruebe. Los defaults son
    | placeholders — sirven para que el driver "cloud" no rompa en dev, pero NO
    | existen en Meta hasta que se aprueben.
    |
    | Variables posicionales de cada body (deben matchear el template aprobado):
    |   emergencia_estoy_bien      → {{1}} nombre del usuario · {{2}} link Google Maps
    |   emergencia_necesito_ayuda  → {{1}} nombre del usuario · {{2}} tracking_url
    |   siniestro_aviso_pas        → {{1}} nombre del cliente · {{2}} contacto del cliente
    |
    */
    'templates' => [
        'emergencia_estoy_bien' => [
            'name' => env('WHATSAPP_TEMPLATE_EMERGENCIA_BIEN', 'mango_emergencia_estoy_bien'),
            'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'es_AR'),
        ],
        'emergencia_necesito_ayuda' => [
            'name' => env('WHATSAPP_TEMPLATE_EMERGENCIA_AYUDA', 'mango_emergencia_necesito_ayuda'),
            'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'es_AR'),
        ],
        'siniestro_aviso_pas' => [
            'name' => env('WHATSAPP_TEMPLATE_SINIESTRO_PAS', 'mango_siniestro_aviso_pas'),
            'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'es_AR'),
        ],
    ],
];
