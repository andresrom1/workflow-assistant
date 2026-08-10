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
 * Desde que la consulta arranca al identificar el vehículo, puede terminar ANTES de que el cliente
 * elija cobertura. Presentar en ese momento se saltearía la pregunta. Los resultados ya quedaron
 * guardados: cuando el cliente elija, `coveragePreference()` vuelve a despachar este job.
 */
it('no presenta las alternativas si el cliente todavía no eligió cobertura', function () {
    Bus::fake([SendWhatsAppMessage::class]);

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

/** Guard preexistente: una segunda notificación sobre la misma cotización no re-presenta. */
it('no re-presenta una cotización ya enviada', function () {
    Bus::fake([SendWhatsAppMessage::class]);

    $this->conversation->updateAiState(['coverage_set' => true, 'quote_ready' => true]);

    $orchestrator = $this->mock(InsuranceOrchestrator::class);
    $orchestrator->shouldNotReceive('handle');

    $quote = cotizacionListaDe($this->conversation);

    NotifyClientQuoteReady::dispatchSync($this->conversation->id, $quote->id);

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});
