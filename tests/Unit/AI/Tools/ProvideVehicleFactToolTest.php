<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Tools\ProvideVehicleFactTool;
use App\Models\Conversation;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

// Este tool envuelve un modelo Eloquent (Conversation); necesita el container
// de Laravel arrancado, así que se sobreescribe el TestCase por defecto de Unit/.
uses(TestCase::class);

it('delegates to the adapter with the given patente and fact', function () {
    $conversation = Mockery::mock(Conversation::class)->makePartial();

    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $adapter->shouldReceive('handleToolCall')
        ->once()
        ->with(['patente' => 'AD123CC', 'fact' => 'automática'], 'provide_vehicle_fact', $conversation)
        ->andReturn(['success' => true, 'tool_output' => 'ok']);

    $tool = new ProvideVehicleFactTool($adapter, $conversation);

    $result = json_decode($tool->handle(new Request(['patente' => 'AD123CC', 'fact' => 'automática'])), true);

    expect($result)->toBe(['success' => true, 'tool_output' => 'ok']);
});

it('does not touch conversation state directly (vehicle_identified stays managed by identify_vehicle)', function () {
    $conversation = Mockery::mock(Conversation::class)->makePartial();
    $conversation->shouldNotReceive('updateAiState');

    $adapter = Mockery::mock(WhatsAppAdapter::class);
    $adapter->shouldReceive('handleToolCall')->andReturn(['success' => true, 'tool_output' => 'ok']);

    $tool = new ProvideVehicleFactTool($adapter, $conversation);
    $tool->handle(new Request(['patente' => 'AD123CC', 'fact' => 'manual']));
});

it('describes its purpose for the LLM', function () {
    $conversation = Mockery::mock(Conversation::class)->makePartial();
    $adapter = Mockery::mock(WhatsAppAdapter::class);

    $tool = new ProvideVehicleFactTool($adapter, $conversation);

    expect($tool->description())->toContain('vehículo');
});
