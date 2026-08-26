<?php

use App\AI\Tools\CheckCoverageRuleTool;
use App\Models\QuoteAlternative;

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
