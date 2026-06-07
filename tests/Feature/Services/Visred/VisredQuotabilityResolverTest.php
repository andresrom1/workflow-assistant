<?php

use App\AI\Agents\DisambiguationAgent;
use App\Models\Vehicle;
use App\Services\Quotability\QuotabilityStatus;
use App\Services\Visred\VisredQuotabilityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CATALOG_BASE = 'https://visred.test/v1/patrimoniales/vehicles/params';

beforeEach(function () {
    config()->set('visred.base_url', 'https://visred.test');
    config()->set('visred.sandbox', false);
    Cache::flush();
    // Token pre-cacheado: evita fakear login en cada test del catálogo.
    Cache::put('visred:access_token', 'TESTTOKEN', 3300);
});

/**
 * Árbol del catálogo Peugeot 2008 2017 (shapes heterogéneos reales, §10).
 * Versión "versions" = el ejemplo verificado live.
 */
function fakeCatalog(): void
{
    Http::fake([
        CATALOG_BASE.'/vehicle-types/' => Http::response([
            ['vehicle_type_id' => 'auto'],
            ['vehicle_type_id' => 'moto'],
        ]),
        CATALOG_BASE.'/auto/brands/' => Http::response([
            ['id' => 32, 'description' => 'PEUGEOT'],
            ['id' => 33, 'description' => 'FIAT'],
        ]),
        CATALOG_BASE.'/auto/32/years/' => Http::response([
            ['id' => '2017', 'description' => '2017'],
            ['id' => '2016', 'description' => '2016'],
        ]),
        CATALOG_BASE.'/auto/32/2017/groups/' => Http::response([
            ['group_id' => 34, 'group_name' => '2008'],
            ['group_id' => 35, 'group_name' => '208'],
        ]),
        CATALOG_BASE.'/auto/32/2017/versions/*' => Http::response([
            ['version_id' => 'AAsport', 'version_name' => '1.6 SPORT THP'],
            ['version_id' => 'AAfelTip', 'version_name' => '1.6 FELINE TIPTRONIC'],
            ['version_id' => 'AAfeline', 'version_name' => '1.6 FELINE'],
            ['version_id' => 'AAallTip', 'version_name' => '1.6 ALLURE TIPTRONIC'],
            ['version_id' => 'AAallure', 'version_name' => '1.6 ALLURE'],
            ['version_id' => 'AAactive', 'version_name' => '1.6 ACTIVE'],
        ]),
    ]);
}

function peugeot(string $version): Vehicle
{
    return Vehicle::factory()->create([
        'marca' => 'Peugeot',
        'modelo' => '2008',
        'year' => 2017,
        'version' => $version,
    ]);
}

function resolver(): VisredQuotabilityResolver
{
    return app(VisredQuotabilityResolver::class);
}

it('Tier 1: resuelve sin LLM cuando hay un único hit léxico', function () {
    fakeCatalog();
    DisambiguationAgent::fake()->preventStrayPrompts();

    $vehicle = peugeot('Active');
    $result = resolver()->check($vehicle);

    expect($result->status)->toBe(QuotabilityStatus::Quotable)
        ->and($result->provider)->toBe('visred')
        ->and($result->externalRef)->toBe('AAactive')
        ->and($result->resolvedVersion)->toBe('1.6 ACTIVE');

    // Refina la versión en el dominio (candado del re-cotizar).
    expect($vehicle->fresh()->version)->toBe('1.6 ACTIVE');

    // Pasó la cadena de params con los ids correctos (incl. group_id en la query).
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/auto/32/2017/versions/')
        && str_contains($r->url(), 'group_id=34'));

    DisambiguationAgent::assertNeverPrompted();
});

it('Tier 1: re-cotización con versión ya refinada colapsa a match exacto, sin LLM', function () {
    fakeCatalog();
    DisambiguationAgent::fake()->preventStrayPrompts();

    $result = resolver()->check(peugeot('1.6 ALLURE'));

    expect($result->status)->toBe(QuotabilityStatus::Quotable)
        ->and($result->externalRef)->toBe('AAallure');

    DisambiguationAgent::assertNeverPrompted();
});

it('Tier 2: el LLM resuelve la ambigüedad de transmisión (Allure → manual)', function () {
    fakeCatalog();
    DisambiguationAgent::fake(['{"decision":"resolved","version_name":"1.6 ALLURE"}']);

    $vehicle = peugeot('Allure');
    $result = resolver()->check($vehicle);

    expect($result->status)->toBe(QuotabilityStatus::Quotable)
        ->and($result->externalRef)->toBe('AAallure')
        ->and($vehicle->fresh()->version)->toBe('1.6 ALLURE');

    DisambiguationAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'ALLURE'));
});

it('Tier 2: el LLM pide el hecho de dominio faltante (NeedsFact)', function () {
    fakeCatalog();
    DisambiguationAgent::fake(['{"decision":"need_fact","missing_fact":"transmisión","options":["automática","manual"]}']);

    $result = resolver()->check(peugeot('Allure'));

    expect($result->status)->toBe(QuotabilityStatus::NeedsFact)
        ->and($result->missingFact)->toBe('transmisión')
        ->and($result->options)->toBe(['automática', 'manual'])
        ->and($result->externalRef)->toBeNull();
});

it('NotQuotable: la marca no está en el catálogo', function () {
    fakeCatalog();
    DisambiguationAgent::fake()->preventStrayPrompts();

    $result = resolver()->check(Vehicle::factory()->create([
        'marca' => 'Lada', 'modelo' => 'Niva', 'year' => 2017, 'version' => 'Base',
    ]));

    expect($result->status)->toBe(QuotabilityStatus::NotQuotable);
    DisambiguationAgent::assertNeverPrompted();
});

it('NotQuotable: un error de Visred cae a la rama honesta, no rompe', function () {
    Http::fake([
        CATALOG_BASE.'/vehicle-types/' => Http::response([['vehicle_type_id' => 'auto']]),
        CATALOG_BASE.'/auto/brands/' => Http::response([
            'success' => false,
            'error' => ['message' => 'down', 'code' => 'external_service_unavailable'],
        ], 503),
    ]);

    $result = resolver()->check(peugeot('Active'));

    expect($result->status)->toBe(QuotabilityStatus::NotQuotable);
});
