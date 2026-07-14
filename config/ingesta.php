<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Extracción de documentos ingestados (v2 — server-side con LLM)
    |--------------------------------------------------------------------------
    |
    | El cliente local (ingestor/) sube texto plano (pdfplumber) + el PDF; el server
    | clasifica y extrae los campos del contrato con este modelo. `deepseek-chat`
    | (no `deepseek-reasoner`): extraer 12 campos planos no necesita razonamiento y
    | el chat es más barato/rápido. Ver ExtractIngestedDocument + IngestaExtractorAgent.
    |
    */

    'extraction_model' => env('INGESTA_EXTRACTION_MODEL', 'deepseek-chat'),

    // Cap duro del texto que se manda al LLM (le pone techo al costo por documento,
    // independiente de lo que mande el cliente).
    'max_text_chars' => 8000,

    /*
    |--------------------------------------------------------------------------
    | CUITs de aseguradoras conocidas
    |--------------------------------------------------------------------------
    |
    | Un `documento_numero` que coincida con uno de estos CUITs NO es del tomador —
    | es el emisor del documento (frecuente que el LLM confunda el CUIT del pie de
    | página con el documento del cliente). Portado de ingestor/app/v5/parser.py
    | (COMPANY_BY_CUIT) + margen de otros emisores vistos en el corpus real.
    |
    */

    'company_cuits' => [
        '30500049460', // Sancor Seguros
        '30500061711', // Río Uruguay
        '30500000127', // Seguros Galicia
        '30714590541', // Experta Seguros
        '34500045339', // San Cristóbal
        '30500065776', // emisor visto en corpus real (certificado Sancor)
    ],

    /*
    |--------------------------------------------------------------------------
    | Alias de compañía → nombre canónico
    |--------------------------------------------------------------------------
    |
    | El LLM puede devolver variantes del nombre ("Sancor Cooperativa de Seguros
    | Ltda." en vez de "Sancor Seguros"). Se normaliza contra esta lista (match por
    | substring, comparando en mayúsculas sin acentos) para que la agrupación de
    | Pendientes y la materialización en `polizas.company` sean consistentes con lo
    | que el sistema ya usa. Sin match → se conserva el nombre que dio el LLM.
    |
    | Los nombres canónicos son los que ya usa `ingested_documents.compania` (verificado
    | contra la base real): "Sancor Seguros", "Río Uruguay", "Seguros Galicia",
    | "San Cristóbal", "Triunfo Cooperativa de Seguros", "Mercantil Andina",
    | "Experta Seguros".
    |
    */

    'company_aliases' => [
        'SANCOR' => 'Sancor Seguros',
        'RIO URUGUAY' => 'Río Uruguay',
        'GALICIA' => 'Seguros Galicia',
        'EXPERTA' => 'Experta Seguros',
        'SAN CRISTOBAL' => 'San Cristóbal',
        'MERCANTIL' => 'Mercantil Andina',
        'TRIUNFO' => 'Triunfo Cooperativa de Seguros',
    ],

];
