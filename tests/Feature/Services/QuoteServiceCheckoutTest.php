<?php

use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * `QuoteService::crearCheckout()` es el punto único de apertura de checkout: lo comparten el
 * agente de WhatsApp, el path OpenAI y el CTA de la vista pública. Los tres dependen de estos
 * guards, así que romperlos acá los rompe en los tres canales.
 */
function alternativaDe(Quote $quote): QuoteAlternative
{
    return QuoteAlternative::factory()->create(['quote_id' => $quote->id]);
}

it('abre el checkout y devuelve el token y la url', function (): void {
    $quote = Quote::factory()->create();
    $alternative = alternativaDe($quote);

    $resultado = app(QuoteService::class)->crearCheckout($quote->id, $alternative->id);

    expect($resultado['ok'])->toBeTrue()
        ->and($resultado['token'])->toHaveLength(10)
        ->and($resultado['url'])->toEndWith($resultado['token']);

    $quote->refresh();

    expect($quote->status)->toBe('checkout_pending')
        ->and($quote->checkout_token)->toBe($resultado['token'])
        ->and($quote->checkout_alternative_id)->toBe($alternative->id);
});

it('marca checkout_done para que el agente no siga vendiendo', function (): void {
    $quote = Quote::factory()->create();
    $alternative = alternativaDe($quote);

    expect($quote->conversation->aiState()['checkout_done'])->toBeFalse();

    app(QuoteService::class)->crearCheckout($quote->id, $alternative->id);

    expect($quote->conversation->refresh()->aiState()['checkout_done'])->toBeTrue();
});

it('rechaza una cotización vencida: los precios valen por el día en que se cotizó', function (): void {
    $quote = Quote::factory()->vencida()->create();
    $alternative = alternativaDe($quote);

    $resultado = app(QuoteService::class)->crearCheckout($quote->id, $alternative->id);

    expect($resultado['ok'])->toBeFalse()
        ->and($resultado['error_code'])->toBe('quote_expired');

    expect($quote->refresh()->checkout_token)->toBeNull();
});

it('rechaza una cotización sin vencimiento, que se trata como vencida', function (): void {
    $quote = Quote::factory()->create(['expires_at' => null]);
    $alternative = alternativaDe($quote);

    $resultado = app(QuoteService::class)->crearCheckout($quote->id, $alternative->id);

    expect($resultado['ok'])->toBeFalse()
        ->and($resultado['error_code'])->toBe('quote_expired');
});

it('rechaza una alternativa que es de otra cotización', function (): void {
    $quote = Quote::factory()->create();
    $ajena = alternativaDe(Quote::factory()->create());

    $resultado = app(QuoteService::class)->crearCheckout($quote->id, $ajena->id);

    expect($resultado['ok'])->toBeFalse()
        ->and($resultado['error_code'])->toBe('alternative_not_found');

    expect($quote->refresh()->checkout_token)->toBeNull();
});

it('rechaza una cotización inexistente o en un estado que no admite checkout', function (): void {
    $service = app(QuoteService::class);

    expect($service->crearCheckout(999999, 1)['error_code'])->toBe('quote_not_found');

    $pendiente = Quote::factory()->create(['status' => 'pending']);
    $alternative = alternativaDe($pendiente);

    expect($service->crearCheckout($pendiente->id, $alternative->id)['error_code'])->toBe('quote_not_found');
});

it('permite reabrir el checkout sobre una cotización ya en checkout_pending', function (): void {
    $quote = Quote::factory()->create();
    $primera = alternativaDe($quote);
    $segunda = alternativaDe($quote);

    $service = app(QuoteService::class);
    $inicial = $service->crearCheckout($quote->id, $primera->id);

    // El cliente cambia de opinión y elige la otra: el token se rota y apunta a la nueva.
    $posterior = $service->crearCheckout($quote->id, $segunda->id);

    expect($posterior['ok'])->toBeTrue()
        ->and($posterior['token'])->not->toBe($inicial['token'])
        ->and($quote->refresh()->checkout_alternative_id)->toBe($segunda->id);
});
