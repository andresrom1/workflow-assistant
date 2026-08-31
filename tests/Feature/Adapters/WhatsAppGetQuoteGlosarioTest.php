<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const ROBO_TOTAL = 'Desaparición del vehículo o una vez aparecido el costo de los faltantes superan el 80% del valor del vehículo.';
const CERRADURAS = 'Daños y/o rotura de cerraduras de las puertas y/o baúl por intento de robo.';
const GRANIZO = 'Daños parciales consecuencia del granizo.';

/**
 * Tres alternativas que comparten coberturas, como llegan de verdad: el vocabulario del proveedor
 * es cerrado, así que el mismo tag aparece en casi todas las filas con la misma descripción.
 */
function cotizacionConCoberturasRepetidas(): array
{
    $customer = Customer::factory()->create();
    Vehicle::factory()->create(['customer_id' => $customer->id, 'patente' => 'AB123CD']);
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $quote = Quote::create([
        'session_uuid' => (string) Str::uuid(),
        'risk_snapshot_id' => RiskSnapshot::factory()->create()->id,
        'conversation_id' => $conversation->id,
        'status' => 'processed',
    ]);

    $filas = [
        ['Galicia', ['Robo Total', 'Cerraduras'], ['Robo Total' => ROBO_TOTAL, 'Cerraduras' => CERRADURAS]],
        ['Sancor', ['Robo Total', 'Granizo'], ['Robo Total' => ROBO_TOTAL, 'Granizo' => GRANIZO]],
        ['Experta', ['Robo Total', 'Cerraduras', 'Granizo'], ['Robo Total' => ROBO_TOTAL, 'Cerraduras' => CERRADURAS, 'Granizo' => GRANIZO]],
    ];

    foreach ($filas as [$aseguradora, $tags, $detalles]) {
        QuoteAlternative::create([
            'quote_id' => $quote->id,
            'aseguradora' => $aseguradora,
            'titulo' => 'C80',
            'normalized_grade' => 'third_party_complete',
            'precio' => 73106.22,
            'moneda' => 'ARS',
            'payment_method_id' => null,
            'features_tags' => $tags,
            'full_details' => $detalles,
        ]);
    }

    return [$conversation, $quote];
}

function payloadDeGetQuote(Conversation $conversation, Quote $quote): array
{
    $result = app(WhatsAppAdapter::class)->handleToolCall(
        ['quoteId' => $quote->id],
        'get_quote',
        $conversation
    );

    return json_decode($result['quotes'], true);
}

/**
 * El corazón del cambio. Antes la descripción de cada cobertura viajaba adentro de CADA
 * alternativa: en la cotización #19 de producción eran 1.588 entradas —33 definiciones repetidas
 * ~48 veces— y 108.827 de los ~135.700 caracteres del payload.
 *
 * Tres alternativas comparten "Robo Total"; su definición tiene que aparecer UNA sola vez en todo
 * el JSON. Si alguien vuelve a meter `full_details` por fila, este test lo caza.
 */
it('manda la definición de cada cobertura una sola vez', function () {
    [$conversation, $quote] = cotizacionConCoberturasRepetidas();

    $result = app(WhatsAppAdapter::class)->handleToolCall(
        ['quoteId' => $quote->id],
        'get_quote',
        $conversation
    );

    // Fragmento sin acentos a propósito: json_encode escapa los no-ASCII (`Desaparición`),
    // así que buscar la descripción tal cual está escrita no matchearía nunca.
    expect(substr_count($result['quotes'], 'costo de los faltantes'))->toBe(1);
});

it('no repite full_details adentro de cada alternativa', function () {
    [$conversation, $quote] = cotizacionConCoberturasRepetidas();

    $payload = payloadDeGetQuote($conversation, $quote);

    expect($payload['alternatives'])->toHaveCount(3);

    foreach ($payload['alternatives'] as $alternativa) {
        expect($alternativa)->not->toHaveKey('full_details')
            ->and($alternativa)->toHaveKey('features_tags');
    }
});

/**
 * El invariante que hace que deduplicar no pierda información: si un tag viaja en `features_tags`
 * y no está en el glosario, el agente ve una cobertura y no sabe qué cubre.
 */
it('el glosario define todos los tags que llevan las alternativas', function () {
    [$conversation, $quote] = cotizacionConCoberturasRepetidas();

    $payload = payloadDeGetQuote($conversation, $quote);

    $tags = collect($payload['alternatives'])->flatMap(fn (array $a): array => $a['features_tags'])->unique();

    expect($tags)->toHaveCount(3);

    foreach ($tags as $tag) {
        expect($payload['glosario'])->toHaveKey($tag);
        expect($payload['glosario'][$tag])->not->toBe('');
    }
});

it('el glosario tiene una entrada por cobertura, no una por alternativa', function () {
    [$conversation, $quote] = cotizacionConCoberturasRepetidas();

    $payload = payloadDeGetQuote($conversation, $quote);

    // 3 coberturas distintas repartidas en 3 alternativas: 7 apariciones, 3 definiciones.
    expect($payload['glosario'])->toHaveCount(3)
        ->and($payload['glosario']['Robo Total'])->toBe(ROBO_TOTAL)
        ->and($payload['glosario']['Cerraduras'])->toBe(CERRADURAS);
});

/**
 * Un plan sin la enumeración del proveedor no se ofrece: sin ella no se puede explicar
 * contractualmente qué cubre. Son `Auto Max 15` y `Garage` de Sancor, los dos únicos en
 * producción. Ver `QuoteAlternative::hasFeatureTags()`.
 */
it('no ofrece la alternativa que vino sin coberturas', function () {
    $customer = Customer::factory()->create();
    Vehicle::factory()->create(['customer_id' => $customer->id, 'patente' => 'AB123CD']);
    $conversation = Conversation::factory()->create(['customer_id' => $customer->id]);

    $quote = Quote::create([
        'session_uuid' => (string) Str::uuid(),
        'risk_snapshot_id' => RiskSnapshot::factory()->create()->id,
        'conversation_id' => $conversation->id,
        'status' => 'processed',
    ]);
    QuoteAlternative::create([
        'quote_id' => $quote->id,
        'aseguradora' => 'Sancor',
        'titulo' => 'Auto Max 15',
        'normalized_grade' => 'basic',
        'precio' => 50000,
        'moneda' => 'ARS',
        'features_tags' => [],
        'full_details' => [],
    ]);

    $payload = payloadDeGetQuote($conversation, $quote);

    expect($payload['glosario'])->toBe([])
        ->and($payload['alternatives'])->toBeEmpty();
});
