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
