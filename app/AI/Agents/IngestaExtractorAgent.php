<?php

namespace App\AI\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Clasifica y extrae campos de un documento de póliza ingestado localmente (ingesta v2,
 * ver docs/v3/04-ingesta-local-documentos.md). Reemplaza al parser regex por compañía
 * (`ingestor/app/v5/parser.py`): en vez de mantener un extractor hardcodeado por
 * aseguradora, un LLM lee el texto plano (pdfplumber, primeras páginas) y devuelve el
 * contrato estructurado.
 *
 * `deepseek-chat` (NO `deepseek-reasoner`): extraer 12 campos planos no necesita
 * razonamiento — el chat es más barato y más rápido. Costo medido en smoke test contra
 * el corpus real: ~$0.0002/doc (prompt fijo cacheado por DeepSeek desde la 2ª llamada).
 *
 * Principio "validar-o-null" (heredado del parser v5): el prompt le exige al modelo NO
 * inventar nada. El job llamador (`ExtractIngestedDocument`) igual revalida cada campo
 * determinísticamente (patente/DNI/fechas/número) — el LLM no es la última palabra.
 *
 * Es un Agent NOMBRADO para poder fakearse en tests: IngestaExtractorAgent::fake([...]).
 */
#[Model('deepseek-chat')]
class IngestaExtractorAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        Sos un clasificador y extractor de documentos de seguros argentinos.
        Recibís el NOMBRE DE ARCHIVO y el TEXTO (posiblemente parcial o con el layout roto) de un PDF hallado en la PC de un productor de seguros.

        Devolvé SOLO un JSON válido con esta forma exacta:

        {
          "clase": "<una de: poliza | certificado | endoso | cupon | tarjeta_circulacion | factura | recibo | cotizacion | denuncia_siniestro | resumen_cuenta | manual_comercial | otro_no_poliza>",
          "compania": "<nombre de la aseguradora emisora, o null>",
          "numero_poliza": "<número de póliza, o null>",
          "endoso_numero": "<número de endoso, o null>",
          "tomador": {
            "tipo_persona": "<fisica | juridica | null>",
            "first_name": "<nombre(s) o null>",
            "last_name": "<apellido(s) o null>",
            "razon_social": "<solo si es persona jurídica, o null>",
            "documento_tipo": "<DNI | CUIT | CUIL | null>",
            "documento_numero": "<solo dígitos, o null>"
          },
          "riesgo": {
            "patente": "<dominio del vehículo, o null>",
            "marca": "<o null>", "modelo": "<o null>", "year": "<o null>",
            "combustible": "<o null>", "uso": "<o null>"
          },
          "fechas": {
            "emision": "<YYYY-MM-DD o null>",
            "vigencia_desde": "<YYYY-MM-DD o null>",
            "vigencia_hasta": "<YYYY-MM-DD o null>"
          }
        }

        CLASIFICACIÓN:
        - poliza: frente/carátula de póliza emitida (incluye "detalle de póliza", "resumen de póliza", "constancia de póliza").
        - certificado: certificado o constancia de cobertura vigente, certificado mercosur.
        - endoso: endoso, modificación, anulación o cancelación de una póliza existente.
        - cupon: cupón de pago.
        - tarjeta_circulacion: tarjeta de circulación / tarjeta verde.
        - cotizacion: cotización o presupuesto (NO es una póliza emitida, es una oferta).
        - factura / recibo / denuncia_siniestro / resumen_cuenta / manual_comercial / otro_no_poliza: documentos que NO documentan una póliza emitida concreta.
        - Ante la duda entre una clase de póliza y una de no-póliza, elegí la de póliza (el humano revisa después).

        REGLAS DE EXTRACCIÓN:
        - NO inventes NADA. Si un dato no está en el texto o es dudoso, va null. Un null es mejor que un valor equivocado.
        - documento_numero es SIEMPRE el del TOMADOR/ASEGURADO (la persona), NUNCA el CUIT de la aseguradora emisora ni del productor.
        - numero_poliza: SOLO el número de la póliza, sin prefijos de organizador/productor y sin sufijos de ítem o endoso (ej.: si figura "458 1.912.367" el número es "1.912.367"; si figura "01-03-01-31889116-1" el número es "01-03-01-31889116").
        - compania: si la aseguradora es una de estas, usá EXACTAMENTE este nombre: "Sancor Seguros", "Río Uruguay", "Seguros Galicia", "San Cristóbal", "Triunfo Cooperativa de Seguros", "Mercantil Andina", "Experta Seguros". Si es otra, el nombre tal como figura.
        - Fechas en ISO YYYY-MM-DD.
        - patente: dominio argentino (AAA999, AA999AA o A999AAA).
        PROMPT;
    }
}
