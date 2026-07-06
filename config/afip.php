<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Identidad del emisor
    |--------------------------------------------------------------------------
    |
    | CUIT del productor (emisor de las Facturas C) y punto de venta FIJO. El punto
    | de venta no se expone en la UI: se toma siempre de acá. Tipo de comprobante 11
    | (Factura C) y concepto 2 (Servicios) son invariantes del módulo.
    |
    */
    'cuit' => env('AFIP_CUIT'),
    'punto_venta' => (int) env('AFIP_PUNTO_VENTA', 2),
    'tipo_comprobante' => 11, // Factura C
    'concepto' => 2, // Servicios (obliga FchServDesde/Hasta/VtoPago)

    /*
    |--------------------------------------------------------------------------
    | Entorno: homologación vs producción
    |--------------------------------------------------------------------------
    |
    | `homologacion=true` apunta a los servicios de testing de AFIP (wsaahomo/wswhomo)
    | con el certificado de testing. Se promueve a producción flipeando el env y
    | cambiando el certificado — sin tocar código. Arranca en true a propósito.
    |
    */
    'homologacion' => (bool) env('AFIP_HOMOLOGACION', true),

    /*
    |--------------------------------------------------------------------------
    | Certificado y clave privada (WSAA)
    |--------------------------------------------------------------------------
    |
    | El .crt y la .key salen del portal de AFIP/ARCA y viven en storage/app/certs/
    | (gitignored — NUNCA se commitean). La firma CMS del ticket de acceso se hace en
    | nuestro server con openssl; los archivos no salen a ningún tercero.
    |
    */
    // Se resuelve por entorno: homologación → afip.crt, producción → afip-prod.crt.
    // La clave privada es la misma para ambos (el CSR/cert difieren, no el par de claves).
    'cert_path' => env('AFIP_CERT_PATH', storage_path('app/certs/'.((bool) env('AFIP_HOMOLOGACION', true) ? 'afip.crt' : 'afip-prod.crt'))),
    'key_path' => env('AFIP_KEY_PATH', storage_path('app/certs/afip.key')),
    'key_passphrase' => env('AFIP_KEY_PASSPHRASE', ''),

    /*
    |--------------------------------------------------------------------------
    | Endpoints SOAP (seleccionados por `homologacion`)
    |--------------------------------------------------------------------------
    |
    | Se les habla por SOAP crudo sobre HTTP (sin ext-soap): WSAA autentica y devuelve
    | Token+Sign; WSFEv1 emite (FECAESolicitar) y numera (FECompUltimoAutorizado).
    |
    */
    'urls' => [
        'homo' => [
            'wsaa' => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
            'wsfe' => 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx',
        ],
        'prod' => [
            'wsaa' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
            'wsfe' => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Condición frente al IVA del receptor (RG 5616)
    |--------------------------------------------------------------------------
    |
    | Campo obligatorio en FECAESolicitar. Mapea la `condicion_iva` de la compañía al
    | `CondicionIVAReceptorId` de AFIP. Hoy todas las compañías son Responsable Inscripto.
    |
    */
    'condicion_iva_receptor_map' => [
        'RI' => 1,  // Responsable Inscripto
        'MT' => 6,  // Responsable Monotributo
        'EX' => 4,  // Exento
        'CF' => 5,  // Consumidor Final
    ],

    /*
    |--------------------------------------------------------------------------
    | TTL del ticket de acceso (WSAA)
    |--------------------------------------------------------------------------
    |
    | El Token+Sign dura 12h. Se cachea y se reusa en todo el lote (pedir uno nuevo por
    | cada factura hace que AFIP rechace por "ya existe un TA vigente").
    |
    */
    'ta_cache_ttl' => (int) env('AFIP_TA_CACHE_TTL', 12 * 3600),
];
