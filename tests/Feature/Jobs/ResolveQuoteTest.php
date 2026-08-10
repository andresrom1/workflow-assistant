<?php

use App\Jobs\NotifyClientQuoteFailed;
use App\Jobs\ResolveQuote;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('resuelve la cotización pendiente', function () {
    Bus::fake([NotifyClientQuoteFailed::class]);

    $quote = Quote::factory()->create(['status' => 'pending']);

    $quoteService = $this->mock(QuoteService::class);
    $quoteService->shouldReceive('resolveQuote')->once()->andReturnTrue();

    ResolveQuote::dispatchSync($quote->id);

    Bus::assertNotDispatched(NotifyClientQuoteFailed::class);
});

/**
 * El cliente pudo revertir de etapa (`revert_to_stage` expira las quotes abiertas) mientras este
 * job esperaba en la cola. Rehacer la consulta sería cotizar un riesgo que ya no es el real.
 */
it('sale limpio cuando la cotización ya no está pendiente', function () {
    Bus::fake([NotifyClientQuoteFailed::class]);

    $quote = Quote::factory()->create(['status' => 'expired']);

    $quoteService = $this->mock(QuoteService::class);
    $quoteService->shouldNotReceive('resolveQuote');

    ResolveQuote::dispatchSync($quote->id);

    Bus::assertNotDispatched(NotifyClientQuoteFailed::class);
});

/**
 * `QuoteService::resolveQuote()` atrapa sus propias excepciones y devuelve false, así que el fallo
 * NO llega por `failed()`. Sin este camino, una cotización caída dejaba al cliente sin ninguna
 * respuesta: ya no hay ningún LLM escuchando del otro lado.
 */
it('avisa al cliente cuando la resolución devuelve false', function () {
    Bus::fake([NotifyClientQuoteFailed::class]);

    $quote = Quote::factory()->create(['status' => 'pending']);

    $quoteService = $this->mock(QuoteService::class);
    $quoteService->shouldReceive('resolveQuote')->once()->andReturnFalse();

    ResolveQuote::dispatchSync($quote->id);

    Bus::assertDispatched(
        NotifyClientQuoteFailed::class,
        fn (NotifyClientQuoteFailed $job): bool => $job->queue === 'whatsapp-outbound'
    );
});
