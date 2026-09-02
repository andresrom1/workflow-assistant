<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Tools\RevertStageTool;
use App\Jobs\NotifyClientQuoteReady;
use App\Jobs\ResolveQuote;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->customer = Customer::factory()->create();
    $this->conversation = Conversation::create([
        'external_conversation_id' => 'thread_revert',
        'ext_user_id' => 'user_revert',
        'customer_id' => $this->customer->id,
        'status' => 'active',
        'last_message_at' => now(),
        'metadata' => ['ai_state' => [
            'customer_identified' => true,
            'vehicle_identified' => true,
            'coverage_set' => true,
            'quote_ready' => true,
            'checkout_done' => false,
        ]],
    ]);
});

it('reverting to vehicle invalidates vehicle onward and expires the open quote', function () {
    // Crea una quote real 'pending' vía el flujo normal (StubQuotability por defecto en tests).
    $adapter = app(WhatsAppAdapter::class);
    $result = $adapter->identifyVehicle([
        'patente' => 'AD123CC',
        'marca' => 'Peugeot',
        'modelo' => '2008',
        'version' => 'Allure',
        'year' => 2017,
        'combustible' => 'nafta',
        'codigo_postal' => '5000',
        'sessionUuid' => Str::uuid()->toString(),
    ], $this->conversation);

    $quoteId = $result['quote_id'];
    Quote::where('id', $quoteId)->update(['status' => 'processed']);

    $tool = new RevertStageTool($adapter, $this->conversation);
    $toolResult = json_decode($tool->handle(new Request(['stage' => 'vehicle'])), true);

    expect($toolResult['success'])->toBeTrue();

    $this->conversation->refresh();
    $state = $this->conversation->aiState();

    expect($state['customer_identified'])->toBeTrue()
        ->and($state['vehicle_identified'])->toBeFalse()
        ->and($state['coverage_set'])->toBeFalse()
        ->and($state['quote_ready'])->toBeFalse()
        ->and($state['checkout_done'])->toBeFalse();

    expect(Quote::find($quoteId)->status)->toBe('expired');
});

it('does not dispatch the outbound notification for an expired quote', function () {
    // `ResolveQuote` también se falsea: `identifyVehicle()` lo despacha, y corriendo inline
    // resuelve la cotización y dispara la notificación real antes de que este test expire la
    // quote — con lo cual el turno que se está midiendo no sería el que dispara el test.
    Bus::fake([SendWhatsAppMessage::class, ResolveQuote::class]);

    $adapter = app(WhatsAppAdapter::class);
    $result = $adapter->identifyVehicle([
        'patente' => 'AD124CC',
        'marca' => 'Peugeot',
        'modelo' => '2008',
        'version' => 'Allure',
        'year' => 2017,
        'combustible' => 'nafta',
        'codigo_postal' => '5000',
        'sessionUuid' => Str::uuid()->toString(),
    ], $this->conversation);

    $quoteId = $result['quote_id'];
    Quote::where('id', $quoteId)->update(['status' => 'expired']);

    // La conversación debe seguir sin quote_ready para no salir por el primer guard.
    $this->conversation->updateAiState(['quote_ready' => false]);

    NotifyClientQuoteReady::dispatchSync($this->conversation->id, $quoteId);

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

it('reverting to coverage keeps customer and vehicle identified', function () {
    $adapter = app(WhatsAppAdapter::class);

    $tool = new RevertStageTool($adapter, $this->conversation);
    $tool->handle(new Request(['stage' => 'coverage']));

    $this->conversation->refresh();
    $state = $this->conversation->aiState();

    expect($state['customer_identified'])->toBeTrue()
        ->and($state['vehicle_identified'])->toBeTrue()
        ->and($state['coverage_set'])->toBeFalse()
        ->and($state['quote_ready'])->toBeFalse();
});
