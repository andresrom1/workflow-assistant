<?php

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('en seco lista los duplicados y no toca nada', function () {
    Customer::factory()->create(['phone' => '+5493516280778', 'dni' => null]);
    Customer::factory()->create(['phone' => '+5493516280778', 'dni' => null]);

    $this->artisan('customers:dedupe')
        ->expectsOutputToContain('Volvé a correrlo con --apply')
        ->assertSuccessful();

    expect(Customer::count())->toBe(2);
});

it('con --apply deja un solo cliente por teléfono y repunta las FKs', function () {
    $viejo = Customer::factory()->create(['phone' => '+5493516280778', 'dni' => null, 'name' => 'Andrés']);
    $nuevo = Customer::factory()->create(['phone' => '+5493516280778', 'dni' => null, 'name' => 'Andrés']);
    $vehiculo = Vehicle::factory()->create(['customer_id' => $nuevo->id]);

    $this->artisan('customers:dedupe --apply')->assertSuccessful();

    expect(Customer::count())->toBe(1)
        ->and(Customer::first()->id)->toBe($viejo->id)
        ->and($vehiculo->fresh()->customer_id)->toBe($viejo->id);
});

it('sobrevive el que tiene documento, aunque sea el más nuevo', function () {
    $sinDocumento = Customer::factory()->create(['phone' => '+5493511111111', 'dni' => null]);
    $conDocumento = Customer::factory()->create(['phone' => '+5493511111111', 'dni' => '30123727']);

    $this->artisan('customers:dedupe --apply')->assertSuccessful();

    expect(Customer::count())->toBe(1)
        ->and(Customer::first()->id)->toBe($conDocumento->id)
        ->and(Customer::find($sinDocumento->id))->toBeNull();
});

it('fusiona también los que comparten BSUID sin compartir teléfono', function () {
    $uno = Customer::factory()->create(['phone' => null, 'dni' => null]);
    $dos = Customer::factory()->create(['phone' => null, 'dni' => null]);
    Conversation::factory()->create(['ext_user_id' => 'AR.mismo', 'customer_id' => $uno->id, 'status' => 'archived']);
    Conversation::factory()->create(['ext_user_id' => 'AR.mismo', 'customer_id' => $dos->id]);

    $this->artisan('customers:dedupe --apply')->assertSuccessful();

    expect(Customer::count())->toBe(1);
});

it('no hace nada cuando no hay duplicados', function () {
    Customer::factory()->create(['phone' => '+5493511111111']);
    Customer::factory()->create(['phone' => '+5493512222222']);

    $this->artisan('customers:dedupe')
        ->expectsOutput('No hay clientes duplicados.')
        ->assertSuccessful();

    expect(Customer::count())->toBe(2);
});
