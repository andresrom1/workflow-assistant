<?php

use App\Models\Customer;
use App\Models\Vehicle;
use App\Services\VehicleIdentificationService;

it('actualiza el año cuando la misma patente se re-apunta a otro auto', function () {
    // Fila vieja: la patente ya existía apuntando a un auto 2017.
    $vehicle = Vehicle::factory()->create([
        'patente' => 'AB413BS',
        'marca' => 'Peugeot',
        'modelo' => '2008',
        'version' => 'Active',
        'year' => 2017,
    ]);

    $newOwner = Customer::factory()->create();

    // El cliente ahora declara OTRO auto con la misma patente (año distinto).
    app(VehicleIdentificationService::class)->updateVehicle($vehicle, $newOwner, [
        'marca' => 'Fiat',
        'modelo' => 'Cronos',
        'version' => 'Attractive',
        'year' => 2021,
        'combustible' => 'gnc',
        'codigo_postal' => '5152',
    ]);

    // El año declarado ahora debe reflejarse — si queda el 2017 viejo, el gate
    // de catálogo (match exacto por año) rechaza un auto que no existe en 2017.
    expect($vehicle->fresh()->year)->toBe(2021)
        ->and($vehicle->fresh()->marca)->toBe('Fiat')
        ->and($vehicle->fresh()->modelo)->toBe('Cronos');
});
