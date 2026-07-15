<?php

use App\Enums\AssetType;
use App\Models\Customer;
use App\Services\PolicyChainResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reusa el asset (y su risk) cuando la patente coincide tras normalizar', function () {
    $customer = Customer::factory()->create();
    $chain = app(PolicyChainResolver::class);

    $r1 = $chain->resolveRisk($customer, AssetType::Vehicle, ['patente' => 'AB 235 OR', 'marca' => 'Toyota']);
    $r2 = $chain->resolveRisk($customer, AssetType::Vehicle, ['patente' => 'ab-235-or']);

    expect($r2->id)->toBe($r1->id)
        ->and($r1->asset->natural_key)->toBe('AB235OR')
        ->and($customer->assets()->count())->toBe(1);
});

it('sin clave natural cada contrato crea su propio asset y risk', function () {
    $customer = Customer::factory()->create();
    $chain = app(PolicyChainResolver::class);

    $r1 = $chain->resolveRisk($customer, AssetType::Person, []);
    $r2 = $chain->resolveRisk($customer, AssetType::Person, []);

    expect($r1->id)->not->toBe($r2->id)
        ->and($customer->assets()->count())->toBe(2);
});

it('el hook saving deriva la natural_key del asset desde type+metadata', function () {
    $customer = Customer::factory()->create();
    $asset = $customer->assets()->create([
        'type' => AssetType::Vehicle,
        'label' => 'Test',
        'metadata' => ['patente' => 'ad 123 bc'],
    ]);

    expect($asset->natural_key)->toBe('AD123BC');
});
