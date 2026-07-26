<?php

use App\Support\Franquicia;

it('extrae la franquicia de los formatos que devuelven las compañías', function (
    string $titulo,
    ?string $porcentaje,
    ?string $minimo,
    ?string $texto,
): void {
    expect(Franquicia::extraer($titulo))->toBe([
        'porcentaje' => $porcentaje,
        'minimo' => $minimo,
        'texto' => $texto,
    ]);
})->with([
    'Galicia' => ['Todo Riesgo Franquicia 4%', '4', null, '4% de la suma asegurada'],
    'Experta' => ['Todo Riesgo XL - Franquicia 5%', '5', null, '5% de la suma asegurada'],
    'San Cristóbal, coma decimal' => [
        'Todo Riesgo Franquicia 7,5% suma asegurada', '7,5', null, '7,5% de la suma asegurada',
    ],
    'Sancor, el porcentaje va antes del marcador' => [
        'Todo Riesgo 8% Suma Aseg, Franquicia', '8', null, '8% de la suma asegurada',
    ],
    'Triunfo, con mínimo' => [
        'D3 - Todo Riesgo Franq 10% - Min $ 400.000',
        '10',
        '400000',
        '10% de la suma asegurada, mínimo $400.000',
    ],
]);

it('no inventa franquicia cuando el título no la declara', function (?string $titulo): void {
    expect(Franquicia::extraer($titulo))->toBe([
        'porcentaje' => null,
        'minimo' => null,
        'texto' => null,
    ]);
})->with([
    'sin porcentaje' => ['Auto Max 15'],
    'terceros completo' => ['C2 FUll'],
    'vacío' => [''],
    'solo espacios' => ['   '],
    'null' => [null],
]);

it('con varios porcentajes gana el que sigue al marcador de franquicia', function (): void {
    expect(Franquicia::extraer('Todo Riesgo 10% dto - Franquicia 4%')['porcentaje'])->toBe('4');
});

it('normaliza el punto decimal a coma', function (): void {
    expect(Franquicia::extraer('Todo Riesgo Franquicia 7.5%')['porcentaje'])->toBe('7,5');
});

it('descarta decimales redundantes', function (): void {
    expect(Franquicia::extraer('Todo Riesgo Franquicia 4,00%')['porcentaje'])->toBe('4');
});

it('no confunde un porcentaje mínimo con un monto mínimo', function (): void {
    expect(Franquicia::extraer('Todo Riesgo Franquicia Mínima 5%'))->toBe([
        'porcentaje' => '5',
        'minimo' => null,
        'texto' => '5% de la suma asegurada',
    ]);
});

it('acepta el mínimo sin signo pesos cuando es un monto', function (): void {
    expect(Franquicia::extraer('Todo Riesgo Franq 10% Min 400.000')['minimo'])->toBe('400000');
});

it('la clave colapsa las variantes equivalentes', function (): void {
    expect(Franquicia::clave('Todo Riesgo Franquicia 4%'))
        ->toBe(Franquicia::clave('Todo Riesgo XL - Franquicia 4%'))
        ->and(Franquicia::clave('Todo Riesgo Franquicia 7,5%'))
        ->toBe(Franquicia::clave('Todo Riesgo Franquicia 7.5%'));
});

it('la clave distingue franquicias distintas', function (): void {
    expect(Franquicia::clave('Todo Riesgo Franquicia 4%'))
        ->not->toBe(Franquicia::clave('Todo Riesgo Franquicia 5%'));
});

it('la clave distingue el mismo porcentaje con y sin mínimo', function (): void {
    expect(Franquicia::clave('Franquicia 10%'))
        ->not->toBe(Franquicia::clave('Franquicia 10% - Min $ 400.000'));
});

// El guard que evita que el dedupe borre productos genuinamente distintos: si el título no se
// puede parsear, dos títulos diferentes NO pueden compartir clave.
it('la clave de títulos no parseables no colapsa productos distintos', function (): void {
    expect(Franquicia::clave('Auto Max 15'))
        ->not->toBe(Franquicia::clave('Garage'))
        ->and(Franquicia::clave('Auto Max 15'))->toStartWith('raw:');
});

it('la clave ignora diferencias de espaciado y capitalización', function (): void {
    expect(Franquicia::clave('  Auto   Max 15 '))->toBe(Franquicia::clave('auto max 15'));
});
