<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Contracts\Quotability;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Vehicle;
use App\Services\Quotability\QuotabilityResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // El turno manda WhatsApp de verdad: con QUEUE_CONNECTION=sync el job de salida corre
    // inline y le pega a la Cloud API. Se falsifica solo el transporte, asi el resto del
    // camino se sigue ejecutando igual. Lo destapo preventStrayRequests() en TestCase.
    Http::fake([
        // Un wamid distinto por llamada: `messages.external_message_id` es unique, y un id
        // fijo hace que el segundo envio del test aborte la transaccion de Postgres (25P02).
        'graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]], 200),
    ]);

    $this->customer = Customer::factory()->create();
    $this->conversation = Conversation::create([
        'external_conversation_id' => 'thread_gate',
        'ext_user_id' => 'user_gate',
        'customer_id' => $this->customer->id,
        'status' => 'identified',
        'last_message_at' => now(),
    ]);
});

/**
 * Bindea una Quotability fija y devuelve el adapter resuelto con ella inyectada.
 */
function adapterWith(QuotabilityResult $result): WhatsAppAdapter
{
    app()->instance(Quotability::class, new class($result) implements Quotability
    {
        public function __construct(private readonly QuotabilityResult $result) {}

        public function check(Vehicle $vehicle): QuotabilityResult
        {
            return $this->result;
        }
    });

    app()->forgetInstance(WhatsAppAdapter::class);

    return app(WhatsAppAdapter::class);
}

function vehicleData(): array
{
    return [
        'patente' => 'AD123CC',
        'marca' => 'Peugeot',
        'modelo' => '2008',
        'version' => 'Allure',
        'year' => 2017,
        'combustible' => 'nafta',
        'codigo_postal' => '5000',
        'sessionUuid' => Str::uuid()->toString(),
    ];
}

it('Quotable: crea la cotización, persiste el token y NO lo filtra al mensaje', function () {
    $adapter = adapterWith(QuotabilityResult::quotable('1.6 ALLURE', 'visred', 'SECRET_TOKEN'));

    $result = $adapter->identifyVehicle(vehicleData(), $this->conversation);

    expect($result['success'])->toBeTrue()
        ->and($result['tool_output'])->toStartWith('Vehículo registrado correctamente.')
        ->and($result['quote_id'] ?? null)->not->toBeNull();

    $this->assertDatabaseHas('risk_provider_refs', [
        'provider' => 'visred',
        'external_vehicle_ref' => 'SECRET_TOKEN',
    ]);

    // Frontera: ni el token ni el nombre del proveedor cruzan al mensaje.
    expect($result['tool_output'])->not->toContain('SECRET_TOKEN')
        ->and($result['tool_output'])->not->toContain('visred');
});

it('NeedsFact: no promete cotización, pide el hecho de dominio y no persiste token', function () {
    $adapter = adapterWith(QuotabilityResult::needsFact('transmisión', ['automática', 'manual']));

    $result = $adapter->identifyVehicle(vehicleData(), $this->conversation);

    expect($result['success'])->toBeTrue()
        ->and($result['tool_output'])->toContain('transmisión')
        ->and($result)->not->toHaveKey('quote_id');

    $this->assertDatabaseCount('risk_provider_refs', 0);
    $this->assertDatabaseCount('quotes', 0);
});

it('NotQuotable: rama honesta, sin promesa rota ni token', function () {
    $adapter = adapterWith(QuotabilityResult::notQuotable());

    $result = $adapter->identifyVehicle(vehicleData(), $this->conversation);

    expect($result['success'])->toBeTrue()
        ->and($result['tool_output'])->toContain('no puedo')
        ->and($result['tool_output'])->toContain('asesor');

    $this->assertDatabaseCount('risk_provider_refs', 0);
    $this->assertDatabaseCount('quotes', 0);

    // Identidad ≠ quotability: el auto SÍ quedó registrado.
    $this->assertDatabaseHas('vehicles', ['patente' => 'AD123CC', 'marca' => 'Peugeot']);
});
