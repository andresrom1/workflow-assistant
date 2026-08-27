<?php

use App\Models\QuoteAlternative;

/**
 * Títulos reales de la cotización #21 de producción. Visred no manda un campo de franquicia:
 * viaja adentro del nombre del producto, y cada compañía lo escribe distinto.
 */
function alternativaConTitulo(string $titulo, float $suma = 28_140_000): QuoteAlternative
{
    return new QuoteAlternative([
        'quote_id' => 1,
        'titulo' => $titulo,
        'sum_asegurada' => $suma,
    ]);
}

it('deriva la franquicia del título por la suma asegurada', function (string $titulo, float $pct, float $monto): void {
    expect(alternativaConTitulo($titulo)->franquicia())
        ->toMatchArray(['porcentaje' => $pct, 'monto' => $monto]);
})->with([
    // San Cristóbal — la que verifiqué a mano: 28.140.000 × 7,5% = 2.110.500
    'San Cristóbal 7,5%' => ['Todo Riesgo Franquicia 7,5% suma asegurada', 7.5, 2_110_500.0],
    'San Cristóbal 5%' => ['Todo Riesgo Franquicia 5% suma asegurada', 5.0, 1_407_000.0],
    'San Cristóbal 2,5%' => ['Todo Riesgo Franquicia 2,5% suma asegurada', 2.5, 703_500.0],
    // Sancor pone el porcentaje ANTES de la palabra
    'Sancor 8%' => ['Todo Riesgo 8% Suma Aseg, Franquicia', 8.0, 2_251_200.0],
    // Río Uruguay abrevia y antepone un código
    'Río Uruguay 7%' => ['T37 - Todo Riesgo Franq 7% Suma Aseg', 7.0, 1_969_800.0],
    'Río Uruguay 1,5%' => ['T38 - Todo Riesgo Franq 1,5% Suma Aseg', 1.5, 422_100.0],
    'Triunfo D4' => ['D4 - Franquicia 5% Suma Asegurada', 5.0, 1_407_000.0],
]);

/**
 * Títulos reales de producción donde HAY un porcentaje pero la cuenta daría un número
 * equivocado. Es el peor error posible acá: una cifra dicha con seguridad. Caen a null, o sea
 * al manual.
 */
it('no deriva cuando el porcentaje no es sobre la suma asegurada', function (string $titulo): void {
    expect(alternativaConTitulo($titulo)->franquicia())->toBeNull();
})->with([
    // La base es el valor del 0km, no la suma asegurada del vehículo usado.
    'San Cristóbal sobre 0KM' => 'Todo Riesgo Franquicia 10% suma 0KM',
    'San Cristóbal 1% sobre 0KM' => 'Todo Riesgo Franquicia 1% suma 0KM',
    // Hay un piso en pesos que el porcentaje no expresa y que puede ganarle.
    'Triunfo con mínimo' => 'D3 - Todo Riesgo Franq 10% - Min $ 400.000',
    // Franquicia en pesos, sin porcentaje.
    'Triunfo en pesos' => 'D2 - Todo Riesgo Franq 30.000/50.000',
]);

it('no inventa franquicia cuando el título no la expresa', function (string $titulo): void {
    expect(alternativaConTitulo($titulo)->franquicia())->toBeNull();
})->with([
    'plan sin franquicia' => 'C Mega',
    'responsabilidad civil' => 'A - Responsabilidad Civil',
    'terceros completos' => 'C1 - Robo e Incendio Total y Parcial',
    'producto de Sancor' => 'Auto Max 15',
    // Dice "franquicia" pero no da porcentaje: el monto sale del manual, no de acá.
    'franquicia fija sin monto' => 'Todo Riesgo Franquicia Fija',
    'todo riesgo sin franquicia' => 'Todo Riesgo Sin Franquicia',
]);

it('no deriva nada sin suma asegurada', function (): void {
    expect(alternativaConTitulo('Todo Riesgo Franquicia 5% suma asegurada', 0.0)->franquicia())
        ->toBeNull();
});

it('conserva el título como origen, para que la cita sea verificable', function (): void {
    expect(alternativaConTitulo('Todo Riesgo Franquicia 5% suma asegurada')->franquicia())
        ->toHaveKey('origen', 'Todo Riesgo Franquicia 5% suma asegurada');
});
