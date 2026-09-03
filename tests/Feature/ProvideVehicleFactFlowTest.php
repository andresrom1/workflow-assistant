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
        'external_conversation_id' => 'thread_vehicle_fact',
        'ext_user_id' => 'user_vehicle_fact',
        'customer_id' => $this->customer->id,
        'status' => 'identified',
        'last_message_at' => now(),
    ]);
});

/**
 * Bindea una Quotability cuyo check() consume una secuencia de resultados
 * (uno por llamada) para simular identify → provide_vehicle_fact.
 *
 * @param  list<QuotabilityResult>  $results
 */
function adapterWithSequence(array $results): WhatsAppAdapter
{
    app()->instance(Quotability::class, new class($results) implements Quotability
    {
        /** @var list<QuotabilityResult> */
        private array $results;

        /** @param  list<QuotabilityResult>  $results */
        public function __construct(array $results)
        {
            $this->results = $results;
        }

        public function check(Vehicle $vehicle): QuotabilityResult
        {
            return array_shift($this->results) ?? QuotabilityResult::notQuotable();
        }
    });

    app()->forgetInstance(WhatsAppAdapter::class);

    return app(WhatsAppAdapter::class);
}

function vehicleFactData(): array
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

it('marks pending_vehicle_fact and creates no quote when identify returns needs_fact', function () {
    $adapter = adapterWithSequence([
        QuotabilityResult::needsFact('transmisión', ['automática', 'manual']),
    ]);

    $adapter->identifyVehicle(vehicleFactData(), $this->conversation);

    $this->conversation->refresh();

    expect($this->conversation->metadata['pending_vehicle_fact'])
        ->toBe(['patente' => 'AD123CC', 'fact' => 'transmisión']);

    $this->assertDatabaseCount('quotes', 0);
});

it('provide_vehicle_fact resolves the catalog match and starts the quote', function () {
    $adapter = adapterWithSequence([
        QuotabilityResult::needsFact('transmisión', ['automática', 'manual']),
        QuotabilityResult::quotable('1.6 ALLURE TIPTRONIC', 'visred', 'SECRET_REF'),
    ]);

    $adapter->identifyVehicle(vehicleFactData(), $this->conversation);
    $this->conversation->refresh();

    $result = $adapter->provideVehicleFact([
        'patente' => 'AD123CC',
        'fact' => 'automática',
    ], $this->conversation);

    expect($result['success'])->toBeTrue()
        ->and($result['tool_output'])->toStartWith('Vehículo registrado correctamente.')
        ->and($result['quote_id'] ?? null)->not->toBeNull();

    // El stub de Quotability no refina Vehicle.version (eso es responsabilidad de
    // VisredQuotabilityResolver::quotable(), ya cubierta en VisredQuotabilityResolverTest).
    // Acá solo verificamos que provideVehicleFact enriqueció la versión con el fact
    // antes de reintentar el gate.
    $vehicle = Vehicle::where('patente', 'AD123CC')->first();
    expect($vehicle->version)->toBe('Allure automática');

    $this->assertDatabaseHas('risk_provider_refs', [
        'provider' => 'visred',
        'external_vehicle_ref' => 'SECRET_REF',
    ]);
    $this->assertDatabaseCount('quotes', 1);

    $this->conversation->refresh();
    expect($this->conversation->metadata)->not->toHaveKey('pending_vehicle_fact');
});

it('provide_vehicle_fact keeps asking when the fact is still ambiguous', function () {
    $adapter = adapterWithSequence([
        QuotabilityResult::needsFact('transmisión', ['automática', 'manual']),
        QuotabilityResult::needsFact('motorización', ['1.6', '2.0']),
    ]);

    $adapter->identifyVehicle(vehicleFactData(), $this->conversation);
    $this->conversation->refresh();

    $result = $adapter->provideVehicleFact([
        'patente' => 'AD123CC',
        'fact' => 'automática',
    ], $this->conversation);

    expect($result['success'])->toBeTrue()
        ->and($result['tool_output'])->toContain('motorización')
        ->and($result)->not->toHaveKey('quote_id');

    $this->assertDatabaseCount('quotes', 0);

    $this->conversation->refresh();
    expect($this->conversation->metadata['pending_vehicle_fact'])
        ->toBe(['patente' => 'AD123CC', 'fact' => 'motorización']);
});

it('coveragePreference instructs asking the pending fact when there is no quote yet', function () {
    $adapter = adapterWithSequence([
        QuotabilityResult::needsFact('transmisión', ['automática', 'manual']),
    ]);

    $adapter->identifyVehicle(vehicleFactData(), $this->conversation);
    $this->conversation->refresh();

    $result = $adapter->coveragePreference([
        'patente' => 'AD123CC',
        'preference' => 'C',
    ], $this->conversation);

    expect($result['success'])->toBeTrue()
        ->and($result['tool_output'])->toContain('provide_vehicle_fact')
        ->and($result['tool_output'])->toContain('transmisión');
});

it('coveragePreference is honest when there is no quote and no pending fact', function () {
    $adapter = adapterWithSequence([
        QuotabilityResult::notQuotable(),
    ]);

    $adapter->identifyVehicle(vehicleFactData(), $this->conversation);
    $this->conversation->refresh();

    $result = $adapter->coveragePreference([
        'patente' => 'AD123CC',
        'preference' => 'C',
    ], $this->conversation);

    expect($result['success'])->toBeTrue()
        ->and($result['tool_output'])->toContain('no hay una cotización en marcha')
        ->and($result['tool_output'])->not->toContain('La oferta será procesada en breve');
});
