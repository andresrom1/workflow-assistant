<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Jobs\ResolveQuote;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->conversation = Conversation::factory()->create([
        'customer_id' => Customer::factory()->create()->id,
    ]);
});

function datosDeVehiculo(): array
{
    return [
        'patente' => 'AD415WE',
        'marca' => 'Nissan',
        'modelo' => 'Kicks',
        'version' => 'Exclusive',
        'year' => 2019,
        'combustible' => 'nafta',
        'codigo_postal' => '5000',
        'sessionUuid' => (string) Str::uuid(),
    ];
}

/**
 * La consulta a las compañías arranca al identificar el vehículo, no al elegir la cobertura: la
 * request a Visred no incluye la preferencia (`buildRequest()` manda vehículo, año y CP), así que
 * no hay nada que esperar. Los 30-174s corren mientras el agente indaga la cobertura.
 */
it('dispara la consulta a las compañías al identificar el vehículo', function () {
    Bus::fake([ResolveQuote::class]);

    $result = app(WhatsAppAdapter::class)->handleToolCall(
        datosDeVehiculo(),
        'identify_vehicle',
        $this->conversation
    );

    expect($result['success'])->toBeTrue();

    $quote = Quote::where('conversation_id', $this->conversation->id)->sole();

    Bus::assertDispatched(ResolveQuote::class);
    expect($quote->status)->toBe('pending');
});

/** El agente tiene que saber que la consulta ya está en marcha para no prometerla como futura. */
it('le avisa al agente que la consulta ya arrancó', function () {
    Bus::fake([ResolveQuote::class]);

    $result = app(WhatsAppAdapter::class)->handleToolCall(
        datosDeVehiculo(),
        'identify_vehicle',
        $this->conversation
    );

    expect($result['tool_output'])->toContain('ya en consulta')
        ->and($result['tool_output'])->toContain('mientras tanto');
});

/**
 * La otra puerta a `onQuotable()`: el vehículo que entró ambiguo y se resolvió con el dato que
 * faltaba. Tiene que disparar igual, si no ese cliente se queda sin cotización en marcha.
 */
it('dispara la consulta cuando el vehículo pasa a cotizable con el dato que faltaba', function () {
    Bus::fake([ResolveQuote::class]);

    app(WhatsAppAdapter::class)->handleToolCall(
        datosDeVehiculo(),
        'identify_vehicle',
        $this->conversation
    );

    Bus::assertDispatchedTimes(ResolveQuote::class, 1);

    app(WhatsAppAdapter::class)->handleToolCall(
        ['patente' => 'AD415WE', 'fact' => 'automática'],
        'provide_vehicle_fact',
        $this->conversation
    );

    Bus::assertDispatchedTimes(ResolveQuote::class, 2);
});
