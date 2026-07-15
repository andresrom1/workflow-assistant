<?php

use App\Enums\AssetType;

it('normaliza la patente como clave natural del vehículo', function () {
    expect(AssetType::Vehicle->naturalKey(['patente' => 'ab 235 or']))->toBe('AB235OR')
        ->and(AssetType::Vehicle->naturalKey(['patente' => 'AB-235-OR']))->toBe('AB235OR')
        ->and(AssetType::Vehicle->naturalKey(['patente' => '  ']))->toBeNull()
        ->and(AssetType::Vehicle->naturalKey([]))->toBeNull();
});

it('los tipos sin identidad re-identificable no tienen clave natural', function () {
    expect(AssetType::Person->naturalKey(['patente' => 'AB235OR']))->toBeNull()
        ->and(AssetType::Property->naturalKey(['ubicacion' => 'Calle Falsa 123']))->toBeNull();
});
