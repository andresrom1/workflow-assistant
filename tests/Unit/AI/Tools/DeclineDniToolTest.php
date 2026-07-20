<?php

use App\AI\Tools\DeclineDniTool;
use App\Models\Conversation;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

// Este tool envuelve un modelo Eloquent (Conversation); necesita el container
// de Laravel arrancado, así que se sobreescribe el TestCase por defecto de Unit/.
uses(TestCase::class);

it('marca customer_identified y registra dni_declined_at cuando ya hay un customer vinculado', function () {
    $conversation = Mockery::mock(Conversation::class)->makePartial();
    $conversation->customer_id = 42;

    $conversation->shouldReceive('updateAiState')
        ->once()
        ->with(['customer_identified' => true]);

    $conversation->shouldReceive('update')
        ->once()
        ->with(Mockery::on(fn (array $attrs): bool => isset($attrs['metadata']['dni_declined_at'])));

    $tool = new DeclineDniTool($conversation);
    $result = json_decode($tool->handle(new Request(['motivo' => 'prefiere no darlo'])), true);

    expect($result['success'])->toBeTrue();
});

it('NO marca customer_identified cuando todavía no hay ningún customer vinculado (BSUID-only)', function () {
    $conversation = Mockery::mock(Conversation::class)->makePartial();
    $conversation->customer_id = null;

    $conversation->shouldNotReceive('updateAiState');
    $conversation->shouldNotReceive('update');

    $tool = new DeclineDniTool($conversation);
    $result = json_decode($tool->handle(new Request([])), true);

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe('missing_customer');
});
