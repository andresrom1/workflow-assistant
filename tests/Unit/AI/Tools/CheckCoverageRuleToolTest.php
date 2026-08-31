<?php

use App\AI\Tools\CheckCoverageRuleTool;
use App\Models\CoverageDocument;
use App\Models\QuoteAlternative;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * El bloque `DATOS DEL PRODUCTO` es lo que el experto lee como fuente primaria.
 *
 * Siempre trae la enumeración: un plan sin `features_tags` no es ofrecible y no llega hasta acá.
 * Ver `QuoteAlternativeOfrecibleTest`.
 */
function bloqueDeProducto(QuoteAlternative $alt): string
{
    return (fn (QuoteAlternative $a): string => $this->productBlock($a))
        ->call(new CheckCoverageRuleTool, $alt);
}

it('habilita negar por ausencia porque la enumeración es completa', function (): void {
    $bloque = bloqueDeProducto(QuoteAlternative::factory()->make(['quote_id' => 1]));

    expect($bloque)
        ->toContain('Esta enumeracion esta COMPLETA')
        ->toContain('Una cobertura que no figura aca, no esta incluida')
        ->toContain('Granizo');
});

it('identifica la aseguradora y el plan', function (): void {
    expect(bloqueDeProducto(QuoteAlternative::factory()->make(['quote_id' => 1])))
        ->toContain('Aseguradora: Sancor')
        ->toContain('Features incluidas');
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

// ── Verificación del fundamento ─────────────────────────────────────────────────

/**
 * @param  array<string, mixed>  $salida
 * @return array<string, mixed>
 */
function verificar(array $salida, string $material, ?QuoteAlternative $alt = null): array
{
    return (fn (array $s, ?QuoteAlternative $a, string $m): array => $this->verificarFundamento($s, $a, $m))
        ->call(new CheckCoverageRuleTool, $salida, $alt, $material);
}

const MATERIAL = 'Granizo: Danos parciales consecuencia del granizo. Cerraduras (1) $300.000';

it('deja pasar la afirmación cuya cita está en el material', function (): void {
    $r = verificar([
        'veredicto' => 'cubierto',
        'respuesta' => 'Si, cubre granizo.',
        'fuente' => 'alcance',
        'cita' => 'Danos parciales consecuencia del granizo',
    ], MATERIAL);

    expect($r)->toMatchArray(['veredicto' => 'cubierto', 'verificado' => true])
        ->and($r)->not->toHaveKey('degradado_por');
});

it('tolera diferencias de espaciado y de caja en la cita', function (): void {
    expect(verificar([
        'veredicto' => 'cubierto', 'respuesta' => 'Si.', 'fuente' => 'alcance',
        'cita' => "DAÑOS  PARCIALES\n  consecuencia   del GRANIZO",
    ], 'Granizo: Daños parciales consecuencia del granizo.'))
        ->toMatchArray(['veredicto' => 'cubierto']);
});

it('deja pasar la afirmación cuya cita no aparece, y la registra', function (): void {
    // La cita mide si el modelo copió y pegó, no si la respuesta es correcta: para una misma
    // respuesta buena hay muchas citas válidas. Un binario colgado de eso tira respuestas
    // correctas, así que se observa y no decide.
    Log::spy();

    expect(verificar([
        'veredicto' => 'cubierto',
        'respuesta' => 'Si, cubre granizo hasta $500.000.',
        'fuente' => 'documentacion',
        'cita' => 'Granizo hasta la suma asegurada de $500.000',
    ], MATERIAL))
        ->toMatchArray(['veredicto' => 'cubierto', 'verificado' => true])
        ->and(verificar(['veredicto' => 'cubierto', 'respuesta' => 'x', 'fuente' => 'documentacion', 'cita' => 'inventada'], MATERIAL))
        ->not->toHaveKey('degradado_por');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $mensaje): bool => str_contains($mensaje, 'la cita no aparece en el material'));
});

it('degrada la afirmación sin cita', function (): void {
    expect(verificar(['veredicto' => 'no_cubierto', 'respuesta' => 'No.', 'fuente' => 'enumeracion', 'cita' => '  '], MATERIAL))
        ->toMatchArray(['veredicto' => 'no_especificado', 'degradado_por' => 'afirmo sin cita']);
});

it('degrada un veredicto que no está en el vocabulario', function (): void {
    expect(verificar(['veredicto' => 'quizas', 'respuesta' => 'Mmm.', 'fuente' => 'alcance', 'cita' => 'Danos parciales consecuencia del granizo'], MATERIAL))
        ->toMatchArray(['veredicto' => 'no_especificado', 'degradado_por' => 'veredicto fuera del vocabulario']);
});

it('degrada a no_especificado cuando el modelo no devolvió nada', function (): void {
    expect(verificar([], MATERIAL))->toMatchArray(['veredicto' => 'no_especificado', 'verificado' => true]);
});

it('conserva no_especificado sin exigirle cita', function (): void {
    expect(verificar([
        'veredicto' => 'no_especificado',
        'respuesta' => 'No tengo ese dato verificado.',
        'fuente' => 'ninguna', 'cita' => '',
    ], MATERIAL))
        ->toMatchArray(['veredicto' => 'no_especificado', 'verificado' => true])
        ->and(verificar(['veredicto' => 'no_especificado', 'respuesta' => 'x', 'fuente' => 'ninguna', 'cita' => ''], MATERIAL))
        ->not->toHaveKey('degradado_por');
});

it('impide negar por ausencia cuando la enumeración no vino, aunque el prompt lo permita', function (): void {
    $sinCoberturas = QuoteAlternative::factory()->sinCoberturas()->make(['quote_id' => 1]);

    // El modelo desobedece el prompt y niega igual. El código lo degrada.
    expect(verificar([
        'veredicto' => 'no_cubierto',
        'respuesta' => 'No, ese plan no cubre granizo.',
        'fuente' => 'enumeracion',
        'cita' => 'Danos parciales consecuencia del granizo',
    ], MATERIAL, $sinCoberturas))
        ->toMatchArray(['veredicto' => 'no_especificado', 'degradado_por' => 'nego por ausencia sin enumeracion']);

    // Con enumeración, la misma negación es legítima.
    expect(verificar([
        'veredicto' => 'no_cubierto',
        'respuesta' => 'No, ese plan no cubre granizo.',
        'fuente' => 'enumeracion',
        'cita' => 'Danos parciales consecuencia del granizo',
    ], MATERIAL, QuoteAlternative::factory()->make(['quote_id' => 1])))
        ->toMatchArray(['veredicto' => 'no_cubierto']);
});

// ── Suma asegurada y franquicia en el bloque de producto ────────────────────────

it('resuelve la franquicia en pesos dentro del bloque de producto', function (): void {
    $alt = QuoteAlternative::factory()->make([
        'quote_id' => 1,
        'titulo' => 'Todo Riesgo Franquicia 7,5% suma asegurada',
        'sum_asegurada' => 28_140_000,
    ]);

    expect(bloqueDeProducto($alt))
        ->toContain('Suma asegurada: $28.140.000')
        ->toContain('Franquicia: 7,5% de la suma asegurada = $2.110.500');
});

it('avisa cuando la franquicia no se puede derivar del título', function (): void {
    $alt = QuoteAlternative::factory()->make([
        'quote_id' => 1, 'titulo' => 'C Mega', 'sum_asegurada' => 28_140_000,
    ]);

    expect(bloqueDeProducto($alt))
        ->toContain('Suma asegurada: $28.140.000')
        ->toContain('no se puede derivar del titulo')
        ->not->toContain('Franquicia: 0');
});

// ── Lo que salió de medir con ai:probe-coverage-qa ──────────────────────────────

it('tolera que el modelo reformatee una fila de tabla', function (): void {
    // Medido: el material trae la fila con pipes y el modelo la cita como prosa, agregando
    // dos puntos. Mismas palabras, mismo número. Con comparación literal se rechazaba en
    // 2 de 3 corridas — una respuesta correcta tirada a la basura.
    $material = '| **Rotura de Cerraduras (3 acontecimientos por vigencia anual**)** | $300.000 | $290.000 |';

    expect(verificar([
        'veredicto' => 'cubierto',
        'respuesta' => 'Hasta $300.000.',
        'fuente' => 'documentacion',
        'cita' => 'Rotura de Cerraduras (3 acontecimientos por vigencia anual): $300.000',
    ], $material))
        ->toMatchArray(['veredicto' => 'cubierto']);
});

it('registra el texto inventado en vez de rechazarlo', function (): void {
    Log::spy();

    expect(verificar([
        'veredicto' => 'cubierto',
        'respuesta' => 'Te cubre hasta 300 km lineales.',
        'fuente' => 'documentacion',
        'cita' => 'Auxilio mecanico con remolque hasta 300 km lineales por evento',
    ], 'Auxilio mecanico y servicio de grua por averia o accidente, dentro del limite establecido.'))
        ->toMatchArray(['veredicto' => 'cubierto'])
        ->not->toHaveKey('degradado_por');

    Log::shouldHaveReceived('warning');
});

it('acepta el nombre de una cobertura como cita, sin piso de longitud', function (): void {
    // Medido: exigir 25 caracteres degradaba 3 de 3 corridas de "¿si me roban el auto?" sobre
    // el plan de RC, cuya cita legítima es "Responsabilidad Civil" — 20 caracteres útiles.
    expect(verificar([
        'veredicto' => 'no_cubierto',
        'respuesta' => 'No, ese plan sólo cubre daños a terceros.',
        'fuente' => 'enumeracion',
        'cita' => 'Responsabilidad Civil',
    ], 'Features incluidas: Responsabilidad Civil, Sistema Cleas', QuoteAlternative::factory()->make(['quote_id' => 1])))
        ->toMatchArray(['veredicto' => 'no_cubierto']);
});

it('no le exige al manual que nombre el plan cotizado', function (): void {
    // El manual se organiza por código de plan y por secciones generales; el título comercial
    // de Visred casi nunca aparece. Medido sobre producción: 20 de 80 planes figuran.
    $alt = QuoteAlternative::factory()->make(['quote_id' => 1, 'titulo' => 'C1 - Robo e Incendio Total y Parcial']);
    $doc = 'Zona de Aplicacion: Republica Argentina y paises limitrofes, sin excepciones.';

    expect(verificar([
        'veredicto' => 'cubierto',
        'respuesta' => 'Si, cubre en paises limitrofes.',
        'fuente' => 'documentacion',
        'cita' => 'Republica Argentina y paises limitrofes',
    ], $doc, $alt))
        ->toMatchArray(['veredicto' => 'cubierto'])
        ->not->toHaveKey('degradado_por');
});
