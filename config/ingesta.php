<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Extracción de documentos ingestados (v2 — server-side con LLM)
    |--------------------------------------------------------------------------
    |
    | El cliente local (ingestor/) sube texto plano (pdfplumber) + el PDF; el server
    | clasifica y extrae los campos del contrato. El modelo NO se configura acá: lo
    | decide `IngestaExtractorAgent` vía `#[UseCheapestModel]` (extraer 12 campos planos
    | no necesita razonamiento). Ver `ai.providers.deepseek.models.text.cheapest` y
    | ExtractIngestedDocument + IngestaExtractorAgent.
    |
    */

    // Cap duro del texto que se manda al LLM (le pone techo al costo por documento,
    // independiente de lo que mande el cliente). 16000 y no menos: las pólizas
    // empaquetadas (Galicia, 22-29 páginas) traen carátulas administrativas adelante y
    // el frente con los datos (DNI en pág. 6) suma ~10k chars desde el inicio — con un
    // cap menor el LLM clasifica bien pero extrae vacío (hallazgo 2026-07-13).
    // ~4k tokens ≈ $0.001/doc en el tier barato: sigue siendo despreciable.
    'max_text_chars' => 16000,

    /*
    |--------------------------------------------------------------------------
    | CUITs de aseguradoras conocidas → nombre canónico
    |--------------------------------------------------------------------------
    |
    | Doble uso (portado de ingestor/app/v5/parser.py COMPANY_BY_CUIT):
    | 1. Detección FUERTE de compañía: si el texto contiene el CUIT de una
    |    aseguradora conocida, esa es la compañía emisora — pisa lo que diga el LLM
    |    (caso real 2026-07-14: el cupón de San Cristóbal no menciona el nombre de
    |    la compañía, solo su CUIT; el LLM adivinó "Sancor" y rompió la agrupación
    |    del contrato).
    | 2. Exclusión de `documento_numero`: un documento que coincida con uno de
    |    estos CUITs NO es del tomador, es el emisor.
    |
    */

    'company_cuits' => [
        '30500049460' => 'Sancor Seguros',
        '30500061711' => 'Río Uruguay',
        '30500000127' => 'Seguros Galicia',
        '30714590541' => 'Experta Seguros',
        '34500045339' => 'San Cristóbal',
    ],

    // Otros CUITs emisores vistos en documentos reales que tampoco son del tomador,
    // pero cuya compañía no identifica al documento (bancos, coaseguros, etc.).
    'other_issuer_cuits' => [
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
