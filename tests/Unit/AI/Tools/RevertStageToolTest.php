<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Tools\RevertStageTool;
use App\Models\Conversation;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

// Este tool envuelve un modelo Eloquent (Conversation); necesita el container
// de Laravel arrancado, así que se sobreescribe el TestCase por defecto de Unit/.
uses(TestCase::class);

it('cascades vehicle revert to vehicle, coverage, quote and checkout flags', function () {
    $conversation = Mockery::mock(Conversation::class)->makePartial();
    $conversation->shouldReceive('updateAiState')
        ->once()
        ->with([
            'vehicle_identified' => false,
            'coverage_set' => false,
            'quote_ready' => false,
            'checkout_done' => false,
        ]);

    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $adapter->shouldReceive('revertStage')
        ->once()
        ->with(['stage' => 'vehicle'], $conversation)
        ->andReturn(['success' => true, 'tool_output' => 'ok']);

    $tool = new RevertStageTool($adapter, $conversation);
    $tool->handle(new Request(['stage' => 'vehicle']));
});

it('cascades coverage revert without touching customer or vehicle flags', function () {
    $conversation = Mockery::mock(Conversation::class)->makePartial();
    $conversation->shouldReceive('updateAiState')
        ->once()
        ->with([
            'coverage_set' => false,
            'quote_ready' => false,
            'checkout_done' => false,
        ]);

    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $adapter->shouldReceive('revertStage')->andReturn(['success' => true, 'tool_output' => 'ok']);

    $tool = new RevertStageTool($adapter, $conversation);
    $tool->handle(new Request(['stage' => 'coverage']));
});

it('cascades customer revert to all five flags', function () {
    $conversation = Mockery::mock(Conversation::class)->makePartial();
    $conversation->shouldReceive('updateAiState')
        ->once()
        ->with([
            'customer_identified' => false,
            'vehicle_identified' => false,
            'coverage_set' => false,
            'quote_ready' => false,
            'checkout_done' => false,
        ]);

    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $adapter->shouldReceive('revertStage')->andReturn(['success' => true, 'tool_output' => 'ok']);

    $tool = new RevertStageTool($adapter, $conversation);
    $tool->handle(new Request(['stage' => 'customer']));
});

it('does not touch ai_state when the adapter reports failure', function () {
    $conversation = Mockery::mock(Conversation::class)->makePartial();
    $conversation->shouldNotReceive('updateAiState');

    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $adapter->shouldReceive('revertStage')
        ->once()
        ->andReturn(['success' => false, 'error' => 'stage inválido', 'error_code' => 'validation_error']);

    $tool = new RevertStageTool($adapter, $conversation);
    $result = json_decode($tool->handle(new Request(['stage' => 'vehicle'])), true);

    expect($result['success'])->toBeFalse();
});
