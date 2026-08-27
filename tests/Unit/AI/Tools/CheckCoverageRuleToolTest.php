<?php

use App\AI\Tools\CheckCoverageRuleTool;
use App\Models\CoverageDocument;
use App\Models\QuoteAlternative;
use Illuminate\Database\Eloquent\Collection;

/**
 * El bloque `DATOS DEL PRODUCTO` decide si el agente puede negar por ausencia.
 *
 * Visred manda algunos covers con `features` vacío — `Auto Max 15` y `Garage` de Sancor, 31 de
 * 2002 alternativas en producción. Con la regla vieja ("feature ausente = no cubierta") el
 * agente afirmaba que `Auto Max 15` —que se vende a $67.737— no cubría nada, para cualquier
 * pregunta, porque con la lista vacía TODA feature está ausente.
 */
function bloqueDeProducto(QuoteAlternative $alt): string
{
    return (fn (QuoteAlternative $a): string => $this->productBlock($a))
        ->call(new CheckCoverageRuleTool, $alt);
}

it('prohíbe negar por ausencia cuando el proveedor no mandó las coberturas', function (): void {
    $alt = QuoteAlternative::factory()->sinCoberturas()->make(['quote_id' => 1]);

    $bloque = bloqueDeProducto($alt);

    expect($bloque)
        ->toContain('ENUMERACION DE COBERTURAS: NO DISPONIBLE')
        ->toContain('PROHIBIDO negar por ausencia')
        // Sin esta frase el agente leería la lista vacía como "no cubre nada".
        ->toContain('NO es que el plan no cubra nada')
        // Y no debe aparecer el bloque de enumeración, que es el que habilita negar.
        ->not->toContain('Features incluidas')
        ->not->toContain('COMPLETA');
});

it('habilita negar por ausencia cuando la enumeración vino', function (): void {
    $alt = QuoteAlternative::factory()->make(['quote_id' => 1]);

    $bloque = bloqueDeProducto($alt);

    expect($bloque)
        ->toContain('Esta enumeracion esta COMPLETA')
        ->toContain('Una cobertura que no figura aca, no esta incluida')
        ->toContain('Granizo')
        ->not->toContain('NO DISPONIBLE');
});

it('mantiene aseguradora y plan en las dos formas del bloque', function (): void {
    $conCoberturas = bloqueDeProducto(QuoteAlternative::factory()->make(['quote_id' => 1]));
    $sinCoberturas = bloqueDeProducto(QuoteAlternative::factory()->sinCoberturas()->make(['quote_id' => 1]));

    expect($conCoberturas)->toContain('Aseguradora: Sancor')
        ->and($sinCoberturas)->toContain('Aseguradora: Sancor')
        ->and($sinCoberturas)->toContain('Garage');
});

// ── Documentación de la compañía ────────────────────────────────────────────────

/** @param  list<CoverageDocument>  $docs */
function bloqueDeDocumentacion(array $docs): string
{
    return (fn (Collection $c): string => $this->documentationBlock($c))
        ->call(new CheckCoverageRuleTool, new Collection($docs));
}

/** @param  list<CoverageDocument>  $docs */
function usaBusqueda(array $docs): bool
{
    return (fn (Collection $c): bool => $this->needsSearchFallback($c))
        ->call(new CheckCoverageRuleTool, new Collection($docs));
}

function documento(string $contenido, string $tipo = 'manual'): CoverageDocument
{
    return new CoverageDocument([
        'company_slug' => 'triunfo',
        'company_name' => 'Triunfo',
        'document_type' => $tipo,
        'original_filename' => 'MANUAL+AUTO.pdf',
        'extracted_content' => $contenido,
    ]);
}

it('avisa que no hay con qué responder cuando la compañía no tiene documentación', function (): void {
    expect(bloqueDeDocumentacion([]))
        ->toContain('NO HAY DOCUMENTACION CARGADA')
        ->toContain('no lo tenes verificado')
        // El riesgo real: deducir el tope del nombre del plan.
        ->toContain('NO lo deduzcas del nombre del plan');

    // Sin documentos la búsqueda tampoco tiene dónde buscar: no se le da la tool.
    expect(usaBusqueda([]))->toBeFalse();
});

it('inyecta la documentación entera y no da la tool de búsqueda cuando entra', function (): void {
    $docs = [documento('## Cuadro de coberturas
| COBERTURA | AUTO MEGA (CM) |
| Cerraduras | $300.000 |')];

    $bloque = bloqueDeDocumentacion($docs);

    expect($bloque)
        ->toContain('AUTO MEGA (CM)')
        ->toContain('$300.000')
        ->toContain('Esto es TODA la documentacion cargada')
        // Las tres reglas que evitan los fallos medidos.
        ->toContain('Si el plan cotizado NO figura en el cuadro')
        ->toContain('camiones o acoplados')
        ->toContain('NO esta especificado');

    expect(usaBusqueda($docs))->toBeFalse();
});

it('cae a la búsqueda sólo cuando la documentación no entra en contexto', function (): void {
    $enorme = [documento(str_repeat('x', 120_001))];

    expect(usaBusqueda($enorme))->toBeTrue()
        ->and(bloqueDeDocumentacion($enorme))
        ->toContain('no entra completa en contexto')
        // Aun cayendo al RAG, se advierte el modo de falla medido.
        ->toContain('devuelve lo mas parecido, NO necesariamente lo que responde')
        ->not->toContain('Esto es TODA la documentacion cargada');

    expect(usaBusqueda([documento(str_repeat('x', 119_999))]))->toBeFalse();
});

it('concatena los documentos de la compañía separándolos', function (): void {
    $bloque = bloqueDeDocumentacion([
        documento('CONTENIDO DEL INSERT', 'insert'),
        documento('CONTENIDO DE ASISTENCIA', 'asistencia'),
    ]);

    expect($bloque)
        ->toContain('CONTENIDO DEL INSERT')
        ->toContain('CONTENIDO DE ASISTENCIA')
        ->toContain('### insert')
        ->toContain('### asistencia');
});
