<?php

use App\Services\Visred\VisredCatalogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('visred.base_url', 'https://visred.test');
    config()->set('visred.sandbox', false);
    config()->set('visred.tax_conditions_path', '/v1/patrimoniales/vehicles/params/tax-conditions/');
    Cache::flush();
    Cache::put('visred:access_token', 'TESTTOKEN', 3300);
});

const TAX_URL = 'https://visred.test/v1/patrimoniales/vehicles/params/tax-conditions/';

it('normaliza las condiciones fiscales a {ref,label}', function () {
    Http::fake([
        TAX_URL => Http::response([
            ['id' => 'CF', 'name' => 'Consumidor Final'],
            ['id' => 'RI', 'name' => 'Responsable Inscripto'],
        ]),
    ]);

    $result = app(VisredCatalogService::class)->taxConditions();

    expect($result)->toBe([
        ['ref' => 'CF', 'label' => 'Consumidor Final'],
        ['ref' => 'RI', 'label' => 'Responsable Inscripto'],
    ]);
});

it('tolera nombres de campo alternativos y descarta filas sin ref', function () {
    Http::fake([
        TAX_URL => Http::response([
            ['code' => 'MT', 'description' => 'Monotributista'],
            ['name' => 'sin id'], // sin ref → descartada
        ]),
    ]);

    $result = app(VisredCatalogService::class)->taxConditions();

    expect($result)->toBe([['ref' => 'MT', 'label' => 'Monotributista']]);
});

it('devuelve [] (sin romper el checkout) si el catálogo falla', function () {
    Http::fake([TAX_URL => Http::response(['error' => 'boom'], 500)]);

    expect(app(VisredCatalogService::class)->taxConditions())->toBe([]);
});

it('cachea el resultado: una sola llamada HTTP en dos invocaciones', function () {
    Http::fake([TAX_URL => Http::response([['id' => 'CF', 'name' => 'Consumidor Final']])]);

    app(VisredCatalogService::class)->taxConditions();
    app(VisredCatalogService::class)->taxConditions();

    Http::assertSentCount(1);
});
