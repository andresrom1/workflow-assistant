<?php

use App\Models\QuoteAlternative;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Un plan sin la enumeración de coberturas del proveedor no se ofrece.
 *
 * Visred manda dos productos de Sancor con `features` vacío — `Auto Max 15` y `Garage`, 35 de las
 * 2.002 alternativas de producción, y son los únicos. Sin la enumeración no se puede explicar
 * contractualmente qué cubre el plan, así que queda fuera de la oferta hasta que el proveedor lo
 * corrija.
 */
it('no ofrece el plan que vino sin enumeracion de coberturas', function (): void {
    $alt = QuoteAlternative::factory()->sinCoberturas()->make(['quote_id' => 1]);

    expect($alt->hasFeatureTags())->toBeFalse()
        ->and($alt->esOfrecible())->toBeFalse();
});

it('ofrece el plan que trae la enumeracion', function (): void {
    $alt = QuoteAlternative::factory()->make(['quote_id' => 1, 'payment_method_id' => null]);

    expect($alt->hasFeatureTags())->toBeTrue()
        ->and($alt->esOfrecible())->toBeTrue();
});

it('sigue mirando el medio de pago cuando la enumeracion vino', function (): void {
    config(['quotes.medios_de_pago_ofrecibles' => ['cbu']]);

    $cobrable = QuoteAlternative::factory()->make(['quote_id' => 1, 'payment_method_id' => 'cbu']);
    $noCobrable = QuoteAlternative::factory()->make(['quote_id' => 1, 'payment_method_id' => 'cupon']);

    expect($cobrable->esOfrecible())->toBeTrue()
        ->and($noCobrable->esOfrecible())->toBeFalse();
});
