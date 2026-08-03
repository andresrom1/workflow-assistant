<?php

use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Services\Quote\QuoteComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(QuoteComparisonService::class);
    $this->quote = Quote::factory()->create();
});

/** Crea una alternativa del grado Todo Riesgo con las coberturas extra que se pidan. */
function alternativa(Quote $quote, string $aseguradora, string $titulo, float $precio, array $extra = []): QuoteAlternative
{
    return QuoteAlternative::factory()
        ->conCoberturas($extra)
        ->create([
            'quote_id' => $quote->id,
            'aseguradora' => $aseguradora,
            'titulo' => $titulo,
            'precio' => $precio,
            'normalized_grade' => 'all_risk',
        ]);
}

// ── Glosario ────────────────────────────────────────────────────────────────

it('arma el glosario con una entrada por tag', function (): void {
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 90317.04, ['Inundación']);

    $glosario = $this->service->glossary($this->quote->alternatives);

    expect($glosario)->toHaveKey('Inundación')
        ->and($glosario['Inundación']['nota'])->toBe('Cubre daños al vehículo a causa de inundación.');
});

it('marca como no-cobertura los tags que son atributo de la compañía o beneficio', function (): void {
    alternativa($this->quote, 'Sancor', 'Todo Riesgo Franquicia 4%', 90000, ['Sistema Cleas', 'Reposición 0KM']);

    $glosario = $this->service->glossary($this->quote->alternatives);

    expect($glosario['Sistema Cleas']['esCobertura'])->toBeFalse()
        ->and($glosario['Reposición 0KM']['esCobertura'])->toBeFalse()
        ->and($glosario['Granizo']['esCobertura'])->toBeTrue();
});

it('una descripción real le gana al placeholder del proveedor', function (): void {
    QuoteAlternative::factory()->create([
        'quote_id' => $this->quote->id,
        'normalized_grade' => 'all_risk',
        'features_tags' => ['Granizo'],
        'full_details' => ['Granizo' => 'Incluido.'],
    ]);
    QuoteAlternative::factory()->create([
        'quote_id' => $this->quote->id,
        'normalized_grade' => 'all_risk',
        'features_tags' => ['Granizo'],
        'full_details' => ['Granizo' => 'Daños parciales consecuencia del granizo.'],
    ]);

    $glosario = $this->service->glossary($this->quote->fresh()->alternatives);

    expect($glosario['Granizo']['nota'])->toBe('Daños parciales consecuencia del granizo.');
});

it('tolera un tag sin descripción', function (): void {
    QuoteAlternative::factory()->create([
        'quote_id' => $this->quote->id,
        'normalized_grade' => 'all_risk',
        'features_tags' => ['Cobertura Nueva'],
        'full_details' => [],
    ]);

    expect($this->service->glossary($this->quote->fresh()->alternatives)['Cobertura Nueva']['nota'])->toBe('');
});

// ── Filtro de alternativas sin coberturas ───────────────────────────────────

// Sancor devuelve "Garage" a $3.321,98 sin coberturas. Si entrara al listado, la card de la
// compañía anunciaría "desde $3.321", que no corresponde a ninguna póliza comparable.
it('descarta las alternativas sin coberturas y no las cuenta en el desde', function (): void {
    QuoteAlternative::factory()->sinCoberturas()->create([
        'quote_id' => $this->quote->id,
        'aseguradora' => 'Sancor',
        'normalized_grade' => 'all_risk',
    ]);
    alternativa($this->quote, 'Sancor', 'Todo Riesgo Franquicia 8%', 78768.77);

    $planes = $this->service->visiblePlans($this->quote->fresh()->alternatives);
    $companias = $this->service->groupByCompany($planes);

    expect($planes)->toHaveCount(1)
        ->and($companias[0]['desde'])->toBe(78768.77);
});

// ── Dedupe ──────────────────────────────────────────────────────────────────

it('colapsa las variantes repetidas a la más barata', function (): void {
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 90317.04);
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 116461.65);

    $planes = $this->service->visiblePlans($this->quote->fresh()->alternatives);

    expect($planes)->toHaveCount(1)
        ->and($planes[0]['precio'])->toBe(90317.04);
});

// `precio` tiene cast decimal:2, así que Eloquent devuelve string: "116461.65" < "90317.04" es
// true comparando strings. Este test falla si en algún lado falta el (float).
it('compara precios como números y no como strings', function (): void {
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 116461.65);
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 90317.04);
    alternativa($this->quote, 'Sancor', 'Todo Riesgo Franquicia 8%', 99113.00);
    alternativa($this->quote, 'Experta', 'Todo Riesgo XL Franquicia 5%', 103927.00);

    $planes = $this->service->visiblePlans($this->quote->fresh()->alternatives);

    expect(array_column($planes, 'precio'))->toBe([90317.04, 99113.00, 103927.00]);
});

it('no colapsa franquicias distintas', function (): void {
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 90317.04);
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 2%', 111473.27);

    expect($this->service->visiblePlans($this->quote->fresh()->alternatives))->toHaveCount(2);
});

it('no colapsa planes que difieren en una cobertura', function (): void {
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 90317.04);
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 95000.00, ['Inundación']);

    expect($this->service->visiblePlans($this->quote->fresh()->alternatives))->toHaveCount(2);
});

it('no cruza compañías distintas aunque coincidan franquicia y coberturas', function (): void {
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 90317.04);
    alternativa($this->quote, 'Sancor', 'Todo Riesgo Franquicia 4%', 91689.82);

    expect($this->service->visiblePlans($this->quote->fresh()->alternatives))->toHaveCount(2);
});

// ── Agrupación ──────────────────────────────────────────────────────────────

it('agrupa por compañía ordenando de la más barata a la más cara', function (): void {
    alternativa($this->quote, 'Experta', 'Todo Riesgo XL Franquicia 5%', 103927.00);
    alternativa($this->quote, 'Triunfo', 'D3 - Todo Riesgo Franquicia 10%', 70447.20);
    alternativa($this->quote, 'Sancor', 'Todo Riesgo Franquicia 8%', 78768.77);

    $companias = $this->service->groupByCompany(
        $this->service->visiblePlans($this->quote->fresh()->alternatives)
    );

    expect(array_column($companias, 'nombre'))->toBe(['Triunfo', 'Sancor', 'Experta'])
        ->and($companias[0]['desde'])->toBe(70447.20)
        ->and($companias[0]['slug'])->toBe('triunfo');
});

it('no afirma una suma asegurada común cuando los planes difieren', function (): void {
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 90317.04);
    QuoteAlternative::factory()->conCoberturas([])->create([
        'quote_id' => $this->quote->id,
        'aseguradora' => 'Galicia',
        'titulo' => 'Todo Riesgo Franquicia 2%',
        'precio' => 111473.27,
        'sum_asegurada' => 20000000,
        'normalized_grade' => 'all_risk',
    ]);

    $companias = $this->service->groupByCompany(
        $this->service->visiblePlans($this->quote->fresh()->alternatives)
    );

    expect($companias[0]['sumaAsegurada'])->toBeNull();
});

// ── Diff ────────────────────────────────────────────────────────────────────

it('reporta lo compartido y lo exclusivo de cada plan', function (): void {
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 90317.04, ['Caída de árboles', 'Sistema Cleas']);
    alternativa($this->quote, 'Triunfo', 'D3 - Todo Riesgo Franquicia 10%', 70447.20);

    $planes = $this->service->visiblePlans($this->quote->fresh()->alternatives);
    $glosario = $this->service->glossary($this->quote->alternatives);
    $diff = $this->service->diff($planes[1], $planes[0], $glosario);

    expect(array_column($diff['soloA'], 'label'))->toBe(['Caída de árboles', 'Sistema Cleas'])
        ->and($diff['soloB'])->toBe([])
        ->and(array_column($diff['comunes'], 'label'))->toContain('Granizo');
});

it('ordena las coberturas antes de lo que no lo es', function (): void {
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 90317.04, ['Sistema Cleas', 'Caída de árboles']);
    alternativa($this->quote, 'Triunfo', 'D3 - Todo Riesgo Franquicia 10%', 70447.20);

    $planes = $this->service->visiblePlans($this->quote->fresh()->alternatives);
    $diff = $this->service->diff($planes[1], $planes[0], $this->service->glossary($this->quote->alternatives));

    expect($diff['soloA'][0]['esCobertura'])->toBeTrue()
        ->and(end($diff['soloA'])['esCobertura'])->toBeFalse();
});

it('calcula la diferencia de precio y la proyección anual', function (): void {
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 90317.04);
    alternativa($this->quote, 'Triunfo', 'D3 - Todo Riesgo Franquicia 10%', 70447.20);

    $planes = $this->service->visiblePlans($this->quote->fresh()->alternatives);
    $diff = $this->service->diff($planes[1], $planes[0], []);

    expect(round($diff['diferenciaPrecio'], 2))->toBe(19869.84)
        ->and(round($diff['ahorroAnual'], 2))->toBe(238438.08);
});

// El dato respalda presencia, no límites: dos planes pueden incluir "Ruedas" con topes distintos.
it('nunca afirma que dos coberturas son iguales', function (): void {
    alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 90317.04);
    alternativa($this->quote, 'Triunfo', 'D3 - Todo Riesgo Franquicia 10%', 70447.20);

    $planes = $this->service->visiblePlans($this->quote->fresh()->alternatives);
    $diff = $this->service->diff($planes[0], $planes[1], []);

    expect(array_keys($diff))->not->toContain('iguales')
        ->and(json_encode($diff))->not->toContain('igual');
});

// ── Vista completa ──────────────────────────────────────────────────────────

it('muestra el grado de la alternativa recomendada aunque sea el minoritario', function (): void {
    $recomendada = QuoteAlternative::factory()->conCoberturas([])->create([
        'quote_id' => $this->quote->id,
        'aseguradora' => 'Galicia',
        'titulo' => 'Terceros Completo Franquicia 4%',
        'precio' => 50000,
        'normalized_grade' => 'third_party_complete',
    ]);
    alternativa($this->quote, 'Sancor', 'Todo Riesgo Franquicia 8%', 78768.77);
    alternativa($this->quote, 'Triunfo', 'D3 - Todo Riesgo Franquicia 10%', 70447.20);

    $this->quote->update(['recommended_alternative_id' => $recomendada->id]);

    $vista = $this->service->buildPublicView($this->quote->fresh());

    expect($vista['grade'])->toBe('third_party_complete')
        ->and($vista['gradeLabel'])->toBe('Terceros Completo')
        ->and($vista['totalOpciones'])->toBe(1);
});

it('sin recomendación cae al grado con más alternativas', function (): void {
    QuoteAlternative::factory()->conCoberturas([])->create([
        'quote_id' => $this->quote->id,
        'normalized_grade' => 'liability',
        'titulo' => 'Responsabilidad Civil',
        'precio' => 30000,
    ]);
    alternativa($this->quote, 'Sancor', 'Todo Riesgo Franquicia 8%', 78768.77);
    alternativa($this->quote, 'Triunfo', 'D3 - Todo Riesgo Franquicia 10%', 70447.20);

    $vista = $this->service->buildPublicView($this->quote->fresh());

    expect($vista['grade'])->toBe('all_risk')
        ->and($vista['gradeLabel'])->toBe('Todo Riesgo')
        ->and($vista['recomendadas'])->toBeNull()
        ->and($vista['comparacion'])->toBeNull();
});

it('expone las dos recomendadas con su razón', function (): void {
    $galicia = alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 90317.04, ['Caída de árboles']);
    $triunfo = alternativa($this->quote, 'Triunfo', 'D3 - Todo Riesgo Franquicia 10%', 70447.20);

    $this->quote->update([
        'recommended_alternative_id' => $galicia->id,
        'presented_alternative_ids' => [$galicia->id, $triunfo->id],
        'presentation_reasons' => [
            (string) $galicia->id => 'La franquicia más baja.',
            (string) $triunfo->id => 'Sale menos por mes.',
        ],
    ]);

    $vista = $this->service->buildPublicView($this->quote->fresh());

    expect($vista['recomendadas']['principal'])->toBe(['planId' => $galicia->id, 'razon' => 'La franquicia más baja.'])
        ->and($vista['recomendadas']['segunda']['planId'])->toBe($triunfo->id)
        ->and(array_column($vista['comparacion']['soloA'], 'label'))->toBe(['Caída de árboles']);
});

// Si el agente recomendó la cara de un par idéntico, la recomendación apunta a la barata en vez
// de perderse cuando el dedupe la elimina.
it('reengancha la recomendación con el plan que sobrevivió al dedupe', function (): void {
    $cara = alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 116461.65);
    $barata = alternativa($this->quote, 'Galicia', 'Todo Riesgo Franquicia 4%', 90317.04);
    $triunfo = alternativa($this->quote, 'Triunfo', 'D3 - Todo Riesgo Franquicia 10%', 70447.20);

    $this->quote->update([
        'recommended_alternative_id' => $cara->id,
        'presented_alternative_ids' => [$cara->id, $triunfo->id],
        'presentation_reasons' => [(string) $cara->id => 'Recomendada', (string) $triunfo->id => 'Alternativa'],
    ]);

    $vista = $this->service->buildPublicView($this->quote->fresh());

    expect($vista['recomendadas']['principal']['planId'])->toBe($barata->id)
        ->and($vista['recomendadas']['principal']['razon'])->toBe('Recomendada');
});

it('extrae la franquicia de cada plan', function (): void {
    alternativa($this->quote, 'Triunfo', 'D3 - Todo Riesgo Franq 10% - Min $ 400.000', 70447.20);

    $vista = $this->service->buildPublicView($this->quote->fresh());

    expect($vista['companias'][0]['planes'][0]['franquicia'])
        ->toBe('10% de la suma asegurada, mínimo $400.000');
});
