<?php

use App\Services\Visred\VisredCatalogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('visred.base_url', 'https://visred.test');
    config()->set('visred.sandbox', false);
    config()->set('visred.tax_conditions_path', '/v1/patrimoniales/vehicles/params/tax-conditions/');
    config()->set('visred.credit_cards_path', '/v1/params/credit-card/');
    Cache::flush();
    Cache::put('visred:access_token', 'TESTTOKEN', 3300);
});

const TAX_URL = 'https://visred.test/v1/patrimoniales/vehicles/params/tax-conditions/';

// El filtro por compañía viaja como query string, así que las URLs del fake se
// distinguen por el `?company_id=`. El global es la misma ruta sin query.
const CARDS_GLOBAL_URL = 'https://visred.test/v1/params/credit-card/';
const CARDS_TRIUNFO_URL = 'https://visred.test/v1/params/credit-card/?company_id=triunfo';
const CARDS_EXPERTA_URL = 'https://visred.test/v1/params/credit-card/?company_id=experta';

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

// ─── Marcas de tarjeta (`credit_card_brand_id`) ───────────────────────────────
//
// El catálogo es POR COMPAÑÍA: lo que se ofrece en el checkout depende de la
// compañía de la alternativa elegida. El global existe solo como red.

it('trae las marcas de la compañía normalizadas a {ref,label}', function () {
    Http::fake([
        CARDS_TRIUNFO_URL => Http::response([
            ['id' => 'visa', 'description' => 'Visa'],
            ['id' => 'kadicard', 'description' => 'Kadicard'],
        ]),
    ]);

    expect(app(VisredCatalogService::class)->creditCards('triunfo'))->toBe([
        ['ref' => 'visa', 'label' => 'Visa'],
        ['ref' => 'kadicard', 'label' => 'Kadicard'],
    ]);
});

it('le da a cada compañía SU catálogo, no el del vecino', function () {
    Http::fake([
        CARDS_TRIUNFO_URL => Http::response([['id' => 'kadicard', 'description' => 'Kadicard']]),
        CARDS_EXPERTA_URL => Http::response([['id' => 'diners-club', 'description' => 'Diners Club']]),
    ]);

    $catalog = app(VisredCatalogService::class);

    expect(array_column($catalog->creditCards('triunfo'), 'ref'))->toBe(['kadicard'])
        ->and(array_column($catalog->creditCards('experta'), 'ref'))->toBe(['diners-club']);
});

it('NO deduplica descripciones repetidas con ids distintos', function () {
    // `amex` y `american-express` conviven en varias compañías con la misma
    // description. Son PKs distintos y no está verificado cuál acepta cada una:
    // elegir uno por el cliente sería asumir un riesgo invisible.
    Http::fake([
        CARDS_TRIUNFO_URL => Http::response([
            ['id' => 'american-express', 'description' => 'American Express'],
            ['id' => 'amex', 'description' => 'American Express'],
        ]),
    ]);

    expect(app(VisredCatalogService::class)->creditCards('triunfo'))->toBe([
        ['ref' => 'american-express', 'label' => 'American Express'],
        ['ref' => 'amex', 'label' => 'American Express'],
    ]);
});

it('cae al catálogo global cuando la compañía no tiene catálogo', function () {
    Http::fake([
        CARDS_TRIUNFO_URL => Http::response([]),
        CARDS_GLOBAL_URL => Http::response([['id' => 'visa', 'description' => 'Visa']]),
    ]);

    expect(app(VisredCatalogService::class)->creditCards('triunfo'))
        ->toBe([['ref' => 'visa', 'label' => 'Visa']]);
});

it('cae al catálogo global cuando el endpoint de la compañía falla', function () {
    Http::fake([
        CARDS_TRIUNFO_URL => Http::response(['error' => 'boom'], 500),
        CARDS_GLOBAL_URL => Http::response([['id' => 'visa', 'description' => 'Visa']]),
    ]);

    expect(app(VisredCatalogService::class)->creditCards('triunfo'))
        ->toBe([['ref' => 'visa', 'label' => 'Visa']]);
});

it('devuelve [] (sin romper el checkout) si tampoco hay catálogo global', function () {
    Http::fake([
        CARDS_TRIUNFO_URL => Http::response(['error' => 'boom'], 500),
        CARDS_GLOBAL_URL => Http::response(['error' => 'boom'], 500),
    ]);

    expect(app(VisredCatalogService::class)->creditCards('triunfo'))->toBe([]);
});

it('sin compañía pide el global directo, sin request por compañía', function (?string $companyId) {
    Http::fake([CARDS_GLOBAL_URL => Http::response([['id' => 'visa', 'description' => 'Visa']])]);

    expect(app(VisredCatalogService::class)->creditCards($companyId))
        ->toBe([['ref' => 'visa', 'label' => 'Visa']]);

    Http::assertSentCount(1);
})->with([
    'null' => [null],
    'string vacío' => [''],
]);

it('cachea por compañía: misma compañía 1 request, dos compañías 2', function () {
    Http::fake([
        CARDS_TRIUNFO_URL => Http::response([['id' => 'kadicard', 'description' => 'Kadicard']]),
        CARDS_EXPERTA_URL => Http::response([['id' => 'diners-club', 'description' => 'Diners Club']]),
    ]);

    $catalog = app(VisredCatalogService::class);
    $catalog->creditCards('triunfo');
    $catalog->creditCards('triunfo');

    Http::assertSentCount(1);

    $catalog->creditCards('experta');

    Http::assertSentCount(2);
});
