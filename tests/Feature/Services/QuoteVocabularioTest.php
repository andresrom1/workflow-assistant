<?php

use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\RiskSnapshot;
use App\Services\Quote\Strategies\ApiQuoteResolution;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * El canario del vocabulario de coberturas. El diff de la vista pública es una diferencia de
 * conjuntos sin diccionario de sinónimos: cuando el proveedor manda una cobertura con un texto
 * nuevo, el diff no se rompe — miente en silencio. Estos tests fijan el aviso.
 */
function cotizacionConTags(array $tags): Quote
{
    $quote = Quote::factory()->create();
    QuoteAlternative::factory()->create([
        'quote_id' => $quote->id,
        'aseguradora' => 'Galicia',
        'features_tags' => $tags,
    ]);

    return $quote;
}

/** Corre resolveQuote() con la estrategia mockeada: lo que se prueba es la auditoría, no el motor. */
function auditar(Quote $quote): void
{
    $strategy = Mockery::mock(ApiQuoteResolution::class);
    $strategy->shouldReceive('getName')->andReturn('api');
    $strategy->shouldReceive('resolve')->once();

    app()->instance(ApiQuoteResolution::class, $strategy);

    app(QuoteService::class)->resolveQuote($quote, RiskSnapshot::factory()->create());
}

it('no dice nada cuando todos los tags son conocidos', function (): void {
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')->never();

    auditar(cotizacionConTags(['Responsabilidad Civil', 'Robo Total', 'Granizo']));
});

it('avisa cuando aparece un tag que no está en el vocabulario', function (): void {
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')
        ->once()
        ->with('coverage.tag_desconocido', Mockery::on(
            fn (array $c): bool => $c['tag'] === 'Caída de árboles' && $c['aseguradora'] === 'Galicia'
        ));

    auditar(cotizacionConTags(['Responsabilidad Civil', 'Caída de árboles']));
});

// El caso peor: no es un concepto nuevo, es el mismo escrito distinto. El diff los ve como
// coberturas diferentes y le muestra al cliente una diferencia que no existe.
it('distingue una variante de escritura de un tag realmente nuevo', function (): void {
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')
        ->once()
        ->with('coverage.tag_variante', Mockery::on(
            fn (array $c): bool => $c['tag'] === 'Destrucción Total por Accidente'
                && $c['variante_de'] === 'Destrucción Total por accidente'
        ));

    auditar(cotizacionConTags(['Destrucción Total por Accidente']));
});

it('marca los tags compuestos aunque ya sean conocidos', function (): void {
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')
        ->once()
        ->with('coverage.tag_compuesto', Mockery::on(
            fn (array $c): bool => $c['tag'] === 'Robo Total y Parcial'
        ));

    // Funde dos conceptos que el resto de las compañías trae separados (`Robo Total` y
    // `Robo Parcial`), así que el diff los cuenta como distintos aunque cubran lo mismo.
    auditar(cotizacionConTags(['Robo Total y Parcial']));
});

it('avisa una sola vez por tag aunque se repita entre alternativas', function (): void {
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')->once()->with('coverage.tag_desconocido', Mockery::any());

    $quote = cotizacionConTags(['Tag Inventado']);
    QuoteAlternative::factory()->create([
        'quote_id' => $quote->id,
        'aseguradora' => 'Triunfo',
        'features_tags' => ['Tag Inventado'],
    ]);

    auditar($quote);
});
