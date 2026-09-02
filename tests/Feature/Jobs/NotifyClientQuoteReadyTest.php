<?php

use App\AI\InsuranceOrchestrator;
use App\Jobs\NotifyClientQuoteReady;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.whatsapp.phone_number_id', '123456789');

    $this->conversation = Conversation::factory()->create([
        'customer_id' => Customer::factory()->create(['phone' => '5491112345678'])->id,
        'ext_user_id' => 'US.13491208655302741918',
    ]);
});

function cotizacionListaDe(Conversation $conversation): Quote
{
    return Quote::factory()->create([
        'conversation_id' => $conversation->id,
        'status' => 'processed',
    ]);
}

/**
 * Presentar sin la cobertura elegida se saltearía esa pregunta. Pero salir en silencio deja la
 * conversación muerta: si el turno que disparó la cotización cerró prometiendo las opciones —el
 * cliente ya había dicho la cobertura, así que el agente no tenía nada que preguntar— no hay
 * ningún mensaje entrante por venir. Pasó en producción; ver ROADMAP, bitácora 2026-09-02.
 */
it('pide la cobertura en vez de presentar si el cliente todavía no la eligió', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->withArgs(fn (string $trigger): bool => str_contains($trigger, 'coverage_preference'))
        ->andReturn([
            'text' => '¿También querés cubrir daños si chocás vos?',
            'agent' => 'CoveragePreferenceAgent',
            'execution_log_ids' => [],
        ]);

    $quote = cotizacionListaDe($this->conversation);

    NotifyClientQuoteReady::dispatchSync($this->conversation->id, $quote->id);

    Bus::assertDispatched(SendWhatsAppMessage::class);
});

/** Si un humano tomó la conversación, el bot no la reabre por su cuenta. */
it('no abre el turno de cobertura si la IA está pausada', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $this->conversation->setAiPaused(true);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldNotReceive('handle');

    $quote = cotizacionListaDe($this->conversation);

    NotifyClientQuoteReady::dispatchSync($this->conversation->id, $quote->id);

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

it('presenta las alternativas una vez elegida la cobertura', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $this->conversation->updateAiState(['coverage_set' => true]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->andReturn([
            'text' => 'Te dejo las dos mejores opciones.',
            'agent' => 'CheckoutAgent',
            'execution_log_ids' => [],
        ]);

    $quote = cotizacionListaDe($this->conversation);

    NotifyClientQuoteReady::dispatchSync($this->conversation->id, $quote->id);

    Bus::assertDispatched(SendWhatsAppMessage::class);
});

/** Guard preexistente: una segunda notificación sobre una cotización ya entregada no re-presenta. */
it('no re-presenta una cotización ya entregada', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $this->conversation->updateAiState(['coverage_set' => true, 'quote_ready' => true]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldNotReceive('handle');

    $quote = cotizacionListaDe($this->conversation);
    $quote->update(['presented_at' => now()]);

    NotifyClientQuoteReady::dispatchSync($this->conversation->id, $quote->id);

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

/**
 * El caso de la conversación 26 (prod, 2026-09-02): `quote_ready` en true porque `get_quote`
 * corrió, pero el proceso murió antes de despachar y el cliente nunca vio nada. Con el flag como
 * guard, todos los reintentos salían en silencio y la cotización se perdía para siempre.
 */
it('rehace la presentación si el estado dice presentada pero nunca se entregó', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $this->conversation->updateAiState(['coverage_set' => true, 'quote_ready' => true]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')
        ->once()
        ->andReturn([
            'text' => 'Te dejo las dos mejores opciones.',
            'agent' => 'CheckoutAgent',
            'execution_log_ids' => [],
        ]);

    $quote = cotizacionListaDe($this->conversation);

    NotifyClientQuoteReady::dispatchSync($this->conversation->id, $quote->id);

    Bus::assertDispatched(SendWhatsAppMessage::class);

    // Se vuelve atrás para que el orquestador entregue QuoteAgent, el único con `get_quote`.
    expect($this->conversation->fresh()->aiState()['quote_ready'])->toBeFalse();
});

/** `presented_at` significa "el cliente la recibió", así que lo sella el despacho. */
it('sella presented_at cuando la presentación sale', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $this->conversation->updateAiState(['coverage_set' => true]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')->once()->andReturn([
        'text' => 'Te dejo las dos mejores opciones.',
        'agent' => 'CheckoutAgent',
        'execution_log_ids' => [],
    ]);

    $quote = cotizacionListaDe($this->conversation);
    $quote->update(['presented_alternative_ids' => [11, 22]]);

    NotifyClientQuoteReady::dispatchSync($this->conversation->id, $quote->id);

    expect($quote->fresh()->presented_at)->not->toBeNull();
});

/** Un turno que despachó cualquier otra cosa no sella nada. */
it('no sella presented_at si la tool de presentación no llegó a correr', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $this->conversation->updateAiState(['coverage_set' => true]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldReceive('handle')->once()->andReturn([
        'text' => 'Dame un segundo.',
        'agent' => 'CheckoutAgent',
        'execution_log_ids' => [],
    ]);

    $quote = cotizacionListaDe($this->conversation);

    NotifyClientQuoteReady::dispatchSync($this->conversation->id, $quote->id);

    expect($quote->fresh()->presented_at)->toBeNull();
});
