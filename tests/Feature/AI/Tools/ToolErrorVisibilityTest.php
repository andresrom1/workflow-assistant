<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Tools\IdentifyCustomerTool;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\CustomerIdentificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Tools\Request;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * Las tools entran por WhatsAppAdapter::handleToolCall(), que es el único lugar donde una
 * excepción queda logueada con su motivo. Cuando llamaban al handler directo, cualquier
 * excepción escapaba al SDK, que la traducía a un texto genérico ("Invalid parameters for
 * tool : X") descartando el mensaje original — y el turno no dejaba ni una línea de log.
 */
beforeEach(function () {
    $this->customer = Customer::factory()->create(['dni' => null, 'phone' => '+5493511234567']);
    $this->conversation = Conversation::factory()->create([
        'customer_id' => $this->customer->id,
        'metadata' => ['ai_state' => ['customer_identified' => false]],
    ]);
});

/** @return array<string, mixed> */
function callIdentify(Conversation $conversation, array $arguments): array
{
    $tool = new IdentifyCustomerTool(app(WhatsAppAdapter::class), $conversation);

    return json_decode($tool->handle(new Request($arguments)), true);
}

it('happy path: el DNI se persiste y cierra el paso', function () {
    $result = callIdentify($this->conversation, [
        'identifier_type' => 'dni',
        'identifier_value' => '30123727',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($this->customer->fresh()->dni)->toBe('30123727')
        ->and($this->customer->fresh()->documento_key)->toBe('30123727')
        ->and($this->conversation->fresh()->aiState()['customer_identified'])->toBeTrue();
});

it('DNI con formato inválido: devuelve el motivo real y lo loguea', function () {
    Log::spy();

    $result = callIdentify($this->conversation, [
        'identifier_type' => 'dni',
        'identifier_value' => '1234',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe('DNI inválido')
        ->and($result['error_code'])->toBe('validation_error')
        ->and($this->customer->fresh()->dni)->toBeNull()
        ->and($this->conversation->fresh()->aiState()['customer_identified'])->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'WhatsApp Adapter: validación fallida'
            && $context['tool'] === 'identify_customer'
            && $context['error'] === 'DNI inválido');
});

it('identifier_value numérico en vez de string: el motivo llega al modelo, no un error genérico', function () {
    Log::spy();

    // Un provider puede mandar el DNI como número JSON en vez de string.
    $result = callIdentify($this->conversation, [
        'identifier_type' => 'dni',
        'identifier_value' => 30123727,
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe('validation_error')
        ->and($result['error'])->not->toBe('Error interno del servidor');

    Log::shouldHaveReceived('warning');
});

it('identifier_type fuera del enum: valida y explica cuál es el problema', function () {
    Log::spy();

    $result = callIdentify($this->conversation, [
        'identifier_type' => 'DNI', // mayúsculas: no matchea `in:email,dni`
        'identifier_value' => '30123727',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe('validation_error');

    Log::shouldHaveReceived('warning');
});

it('parámetro faltante: no explota, devuelve error estructurado', function () {
    Log::spy();

    $result = callIdentify($this->conversation, ['identifier_type' => 'dni']);

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe('validation_error');

    Log::shouldHaveReceived('warning');
});

it('excepción inesperada del service: se loguea con clase, archivo y línea', function () {
    Log::spy();

    $this->mock(CustomerIdentificationService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('resolveForConversation')->andThrow(new RuntimeException('boom'));
    });

    $result = callIdentify($this->conversation, ['identifier_type' => 'dni', 'identifier_value' => '30123727']);

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe('server_error');

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'WhatsApp Adapter: error interno'
            && $context['tool'] === 'identify_customer'
            && $context['exception'] === RuntimeException::class
            && $context['msg'] === 'boom'
            && isset($context['at'], $context['trace'], $context['conversation_id'], $context['payload']));
});

it('TypeError: lo atrapa igual que una excepción (catch \\Throwable, no \\Exception)', function () {
    Log::spy();

    $this->mock(CustomerIdentificationService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('resolveForConversation')->andThrow(new TypeError('argumento con el tipo equivocado'));
    });

    $result = callIdentify($this->conversation, ['identifier_type' => 'dni', 'identifier_value' => '30123727']);

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe('server_error');

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $context['exception'] === TypeError::class
            && $context['msg'] === 'argumento con el tipo equivocado');
});
