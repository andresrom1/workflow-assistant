<?php

use App\Contracts\QuotationProvider;
use App\Jobs\NotifyClientQuoteReady;
use App\Models\Conversation;
use App\Models\Quote;
use App\Models\RiskProviderRef;
use App\Models\RiskSnapshot;
use App\Services\Quote\Strategies\ApiQuoteResolution;
use App\Services\Visred\VisredQuotationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

const COTIZAR_URL = 'https://visred.test/v1/patrimoniales/vehicles/cotizar/';

const COMPANIES_URL = 'https://visred.test/v1/discovery/companies/';

const DISCOUNT_URL = 'https://visred.test/v1/patrimoniales/vehicles/params/discount*';

beforeEach(function () {
    config()->set('visred.base_url', 'https://visred.test');
    config()->set('visred.sandbox', false);
    config()->set('visred.poll_budget', 12);
    config()->set('visred.poll_interval', 2);
    Cache::flush();
    Cache::put('visred:access_token', 'TESTTOKEN', 3300);
    Sleep::fake();
});

function snapshotWithToken(string $token = 'AAallure', array $attrs = []): RiskSnapshot
{
    $snapshot = RiskSnapshot::factory()->create($attrs);
    RiskProviderRef::create([
        'risk_snapshot_id' => $snapshot->id,
        'provider' => 'visred',
        'external_vehicle_ref' => $token,
    ]);

    return $snapshot;
}

/**
 * Conjuntos de coberturas del proveedor, en orden creciente de nivel. El grade se deriva de
 * acá y NO del nombre del plan, así que un fixture con el nombre "Todo Riesgo" y features de
 * RC clasifica como `liability` — igual que en producción.
 *
 * @return list<array{id: string, name: string, description: string}>
 */
function coberturasRc(): array
{
    return [
        ['id' => 'responsabilidad-civil', 'name' => 'Responsabilidad Civil', 'description' => 'Daños a terceros.'],
    ];
}

/** @return list<array{id: string, name: string, description: string}> */
function coberturasBasico(): array
{
    return [
        ...coberturasRc(),
        ['id' => 'robo-total', 'name' => 'Robo Total', 'description' => 'Robo del vehículo completo.'],
        ['id' => 'incendio-total', 'name' => 'Incendio Total', 'description' => 'Incendio del vehículo completo.'],
    ];
}

/**
 * Terceros completo pelado. Trae cristales laterales y cerraduras a propósito: **no** cuentan
 * como adicional, y es justo lo que separa la Tercero Completo L de Experta de la XL.
 *
 * @return list<array{id: string, name: string, description: string}>
 */
function coberturasTercerosCompletos(): array
{
    return [
        ...coberturasBasico(),
        ['id' => 'robo-parcial', 'name' => 'Robo Parcial', 'description' => 'Robo de partes.'],
        ['id' => 'cristales-laterales', 'name' => 'Cristales Laterales', 'description' => 'Cristales de las puertas.'],
        ['id' => 'cerraduras', 'name' => 'Cerraduras', 'description' => 'Cerraduras forzadas.'],
    ];
}

/** @return list<array{id: string, name: string, description: string}> */
function coberturasTercerosCompletosConAdicionales(): array
{
    return [
        ...coberturasTercerosCompletos(),
        ['id' => 'granizo', 'name' => 'Granizo', 'description' => 'Daños parciales consecuencia del granizo.'],
        ['id' => 'parabrisas', 'name' => 'Parabrisas', 'description' => 'Rotura del parabrisas.'],
    ];
}

/** @return list<array{id: string, name: string, description: string}> */
function coberturasTodoRiesgo(): array
{
    return [
        ...coberturasTercerosCompletosConAdicionales(),
        ['id' => 'danos-parciales', 'name' => 'Daños Parciales', 'description' => 'Daños al propio vehículo.'],
    ];
}

/**
 * @param  list<array{id: string, name: string, description: string}>|null  $features
 */
function coverResult(int $id, string $coverId, string $coverName, float $fee, bool $active = true, ?array $features = null, string $paymentMethodId = 'tarjeta'): array
{
    return [
        'quotation_result_id' => $id,
        'cover' => ['id' => $coverId, 'additional_code' => 'def', 'name' => $coverName, 'description' => "Detalle {$coverName}", 'static_fee' => null, 'active' => $active],
        'validity_id' => 'semestral',
        'fee' => $fee,
        'installments' => 12,
        'franchise' => null,
        'insured_amount' => 14_200_000,
        'payment_method_id' => $paymentMethodId,
        'features' => $features ?? coberturasRc(),
        'require_inspection_before_emission' => false,
        'requires_gnc_details' => false,
    ];
}

/**
 * Shape real de GET /v1/tasks/{id}/ → result.company_id + result.quotation_results[].
 */
function taskSuccess(string $companyId, array $quotationResults)
{
    return Http::response([
        'id' => $companyId,
        'status' => 'SUCCESS',
        'ready' => true,
        'message' => null,
        'result' => [
            'company_id' => $companyId,
            'quotation_results' => $quotationResults,
        ],
    ]);
}

/**
 * Shape real de GET /v1/discovery/companies/ → lista plana company_id → company_name.
 */
function companiesResponse()
{
    return Http::response([
        ['company_id' => 'san-cristobal', 'company_name' => 'San Cristóbal', 'product_id' => 'auto', 'product_name' => 'Auto'],
        ['company_id' => 'sancor', 'company_name' => 'Sancor', 'product_id' => 'auto', 'product_name' => 'Auto'],
        ['company_id' => 'rus', 'company_name' => 'Rio Uruguay', 'product_id' => 'auto', 'product_name' => 'Auto'],
    ]);
}

it('cotiza, hace polling y aplana company→covers a alternativas neutras', function () {
    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [
            ['task_id' => 't-sc', 'company_id' => 'san-cristobal'],
            ['task_id' => 't-sancor', 'company_id' => 'sancor'],
        ]]),
        'https://visred.test/v1/tasks/t-sc/' => taskSuccess('san-cristobal', [
            coverResult(7386, 'todo-riesgo-c', 'Todo Riesgo C', 78450.0, features: coberturasTodoRiesgo()),
            coverResult(7387, 'terceros-completo', 'Terceros Completo', 42000.0, features: coberturasTercerosCompletos()),
        ]),
        'https://visred.test/v1/tasks/t-sancor/' => taskSuccess('sancor', [
            coverResult(9001, 'rc', 'Responsabilidad Civil', 12000.0, features: coberturasRc()),
        ]),
    ]);

    $result = app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken());

    expect($result['status'])->toBe('SUCCESS')
        ->and($result['parsed_alternatives'])->toHaveCount(3);

    $first = $result['parsed_alternatives'][0];
    expect($first['aseguradora'])->toBe('San Cristóbal')
        ->and($first['titulo'])->toBe('Todo Riesgo C')
        ->and($first['normalized_grade'])->toBe('all_risk')
        ->and($first['precio'])->toBe(78450.0)
        ->and($first['external_quote_id'])->toBe('7386'); // quotation_result_id

    $grades = array_column($result['parsed_alternatives'], 'normalized_grade');
    expect($grades)->toBe(['all_risk', 'third_party_complete', 'liability']);
});

/**
 * El grade sale de las coberturas, no del nombre del plan. Los casos son los reales de la
 * cotización #10 de producción, donde el match por nombre dejaba `third_party_complete` en 0:
 * ningún proveedor emite el string "terceros completo", y el fallback matcheaba `rc` como
 * substring de pa*rc*ial y te*rc*ero.
 */
it('clasifica el grade por las coberturas y no por el nombre del plan', function (
    string $coverId,
    string $coverName,
    array $features,
    string $esperado,
) {
    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('sancor', [
            coverResult(1, $coverId, $coverName, 1000.0, features: $features),
        ]),
    ]);

    $result = app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken());

    expect($result['parsed_alternatives'][0]['normalized_grade'])->toBe($esperado);
})->with([
    // Nombres que no dicen nada: antes caían todos al default `basic`.
    'un cover id que enumera "parcial" no es responsabilidad civil' => [
        'c1-robo-e-incendio-total-y-parcial', 'C1 - Robo e Incendio Total y Parcial',
        coberturasTercerosCompletos(), 'third_party_complete',
    ],

    // Robo/incendio total sin parcial: es un B aunque el nombre diga otra cosa.
    'robo total sin parcial es basico' => [
        'b3-robo-total', 'B3 - Robo Total', coberturasBasico(), 'basic',
    ],

    // Sin robo ni incendio es responsabilidad civil, aunque el nombre venga con un typo.
    'solo responsabilidad civil' => [
        'a-responsabilidad-civil', 'A - Responsablidad Civil', coberturasRc(), 'liability',
    ],

    // `Daños Parciales` exacto marca Todo Riesgo...
    'danos parciales marca todo riesgo' => [
        'todo-riesgo-franquicia-4', 'Todo Riesgo Franquicia 4%', coberturasTodoRiesgo(), 'all_risk',
    ],
    // ...pero las variantes "por Robo" y "al Amparo del Robo Total" son nivel C, no D.
    'danos parciales al amparo del robo no es todo riesgo' => [
        'c-robo-e-incendio-total-y-parcial-dest-total', 'C - Auto Plus',
        [
            ...coberturasBasico(),
            ['id' => 'danos-parciales-amparo-robo', 'name' => 'Daños Parciales al Amparo del Robo Total', 'description' => 'Daños durante el robo.'],
        ],
        'third_party_complete',
    ],

    // Sancor manda "Auto Max 15" y "Garage" sin ninguna feature. Se conserva el default
    // histórico: sin datos no se puede afirmar que no cubren robo.
    'sin features conserva basic' => [
        'garage', 'Garage', [], 'basic',
    ],
]);

/**
 * El escalón C / C+A, con los pares reales de la cotización de producción #12. Cada compañía
 * tiene los dos y los nombra: L contra XL, BASICA contra PLUS, Auto Max 3 contra Auto Max 6.
 */
it('separa el terceros completo pelado del que trae adicionales', function (
    string $coverId,
    string $coverName,
    array $features,
    string $esperado,
) {
    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('sancor', [
            coverResult(1, $coverId, $coverName, 1000.0, features: $features),
        ]),
    ]);

    $result = app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken());

    expect($result['parsed_alternatives'][0]['normalized_grade'])->toBe($esperado);
})->with([
    'Galicia C80 no trae adicionales' => [
        'c80', 'C80', coberturasTercerosCompletos(), 'third_party_complete',
    ],
    'Galicia C Clima trae granizo' => [
        'c-clima', 'C Clima', coberturasTercerosCompletosConAdicionales(), 'third_party_complete_plus',
    ],
    'Experta Tercero Completo L no trae adicionales' => [
        'tercero-completo-l', 'Tercero Completo L', coberturasTercerosCompletos(), 'third_party_complete',
    ],
    'Experta Tercero Completo XL trae adicionales' => [
        'tercero-completo-xl', 'Tercero Completo XL', coberturasTercerosCompletosConAdicionales(), 'third_party_complete_plus',
    ],
    'Sancor Auto Max 3 no trae adicionales' => [
        'auto-max-3', 'Auto Max 3', coberturasTercerosCompletos(), 'third_party_complete',
    ],
    'Sancor Auto Max 6 trae adicionales' => [
        'auto-max-6', 'Auto Max 6', coberturasTercerosCompletosConAdicionales(), 'third_party_complete_plus',
    ],

    // Cada adicional alcanza por sí solo. Inundación va por prefijo porque el vocabulario
    // trae también `Inundación o Desbordamiento`.
    'la luneta sola alcanza' => [
        'c8', 'C8',
        [...coberturasTercerosCompletos(), ['id' => 'luneta', 'name' => 'Luneta', 'description' => 'Luneta trasera.']],
        'third_party_complete_plus',
    ],
    'inundacion o desbordamiento alcanza' => [
        'c2-full', 'C2 FUll',
        [...coberturasTercerosCompletos(), ['id' => 'inundacion', 'name' => 'Inundación o Desbordamiento', 'description' => 'Inundación.']],
        'third_party_complete_plus',
    ],
    'caida de arboles alcanza' => [
        'c-clima', 'C Clima',
        [...coberturasTercerosCompletos(), ['id' => 'caida-arboles', 'name' => 'Caída de árboles', 'description' => 'Caída de árboles.']],
        'third_party_complete_plus',
    ],

    // El escalón vive SOLO dentro de C: sin robo parcial no hay C+A por más granizo que traiga.
    'granizo sin robo parcial sigue siendo basico' => [
        'auto-max-15', 'Auto Max 15',
        [...coberturasBasico(), ['id' => 'granizo', 'name' => 'Granizo', 'description' => 'Granizo.']],
        'basic',
    ],
]);

/**
 * Visred cotiza el mismo cover una vez por medio de pago y el cupón sale más caro. Sin este
 * campo las filas quedan indistinguibles y el cupón entra como si fuera otro producto.
 */
it('conserva el medio de pago de cada alternativa', function () {
    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'san-cristobal']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('san-cristobal', [
            coverResult(1, 'c-mega', 'C Mega', 100308.0, paymentMethodId: 'cbu'),
            coverResult(2, 'c-mega', 'C Mega', 100308.0, paymentMethodId: 'tarjeta'),
            coverResult(3, 'c-mega', 'C Mega', 122971.0, paymentMethodId: 'cupon'),
        ]),
    ]);

    $result = app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken());

    expect(array_column($result['parsed_alternatives'], 'payment_method_id'))
        ->toBe(['cbu', 'tarjeta', 'cupon']);
});

it('manda el request con el version_id resuelto y los datos del snapshot', function () {
    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('sancor', [coverResult(1, 'rc', 'RC', 1.0)]),
    ]);

    app(VisredQuotationProvider::class)->generateAlternatives(
        snapshotWithToken('TOKEN_X', ['year' => 2019, 'combustible' => 'gnc', 'codigo_postal' => '5000', 'dni' => '30111222'])
    );

    Http::assertSent(fn (Request $r) => $r->url() === COTIZAR_URL
        && $r['vehicle']['version_id'] === 'TOKEN_X'
        && $r['vehicle']['year'] === 2019
        && $r['vehicle']['fuel_type_id'] === 'gnc'
        && $r['address']['zip_code'] === 5000
        && $r['person_holder']['document_number'] === '30111222');
});

it('manda insured_amount_fuel (default configurable) cuando el equipo es GNC', function () {
    config()->set('visred.default_gnc_amount', 1_500_000);

    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('sancor', [coverResult(1, 'rc', 'RC', 1.0)]),
    ]);

    // GNC: Visred exige el monto del equipo. No se captura en cotización → default.
    app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken('T_GNC', ['combustible' => 'gnc']));
    Http::assertSent(fn (Request $r) => $r->url() === COTIZAR_URL
        && $r['vehicle']['fuel_type_id'] === 'gnc'
        && $r['vehicle']['insured_amount_fuel'] === 1_500_000);

    // No-GNC: el campo NO se manda (Visred lo exige solo para 'gnc'). Nafta mapea al
    // binario 'sin-gnc' (fuel_type_id es la pregunta de GNC, no el combustible).
    app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken('T_NAFTA', ['combustible' => 'nafta']));
    Http::assertSent(fn (Request $r) => $r->url() === COTIZAR_URL
        && $r['vehicle']['fuel_type_id'] === 'sin-gnc'
        && ! isset($r['vehicle']['insured_amount_fuel']));
});

it('manda person_holder con el DNI real del snapshot cuando ya lo tiene', function () {
    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('sancor', [coverResult(1, 'rc', 'RC', 1.0)]),
    ]);

    app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken('TOKEN_X', ['dni' => '30111222']));

    Http::assertSent(fn (Request $r) => $r->url() === COTIZAR_URL
        && $r['person_holder']['document_number'] === '30111222');
});

it('omite person_holder si el snapshot todavía no tiene DNI (sin placeholder — evita el 400 de emisión)', function () {
    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('sancor', [coverResult(1, 'rc', 'RC', 1.0)]),
    ]);

    // A propósito: NO se inventa un DNI. Un placeholder cotiza bien pero la emisión
    // exige que coincida con el de la cotización — coincidir con un valor inventado
    // no es alcanzable (ver docs/v2/08 §2.2, verificado en prod 2026-07-19).
    app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken('TOKEN_X', ['dni' => null]));

    Http::assertSent(fn (Request $r) => $r->url() === COTIZAR_URL && ! isset($r['person_holder']));
});

it('mapea el combustible de dominio al binario sin-gnc/gnc (no al combustible específico)', function () {
    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('sancor', [coverResult(1, 'rc', 'RC', 1.0)]),
    ]);

    // fuel_type_id es la pregunta binaria de GNC. Todo lo no-GNC → 'sin-gnc'; GNC → 'gnc'.
    // Galicia y RUS rechazan cualquier otro valor (ver docs/v2/08 §2.2). Diésel con acento
    // ejercita también la normalización de fuelTypeId.
    $expected = [
        'T_NAFTA' => ['nafta', 'sin-gnc'],
        'T_DIESEL' => ['Diésel', 'sin-gnc'],
        'T_ELEC' => ['electrico', 'sin-gnc'],
        'T_HIB' => ['hibrido', 'sin-gnc'],
        'T_CONGNC' => ['con-gnc', 'gnc'],
    ];

    foreach ($expected as $token => [$combustible, $fuelTypeId]) {
        app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken($token, ['combustible' => $combustible]));
        Http::assertSent(fn (Request $r) => $r->url() === COTIZAR_URL
            && $r['vehicle']['version_id'] === $token
            && $r['vehicle']['fuel_type_id'] === $fuelTypeId);
    }
});

it('omite fuel_type_id cuando el combustible no se reconoce (sin asumir uno)', function () {
    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('sancor', [coverResult(1, 'rc', 'RC', 1.0)]),
    ]);

    app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken('T3', ['combustible' => 'magia']));

    Http::assertSent(fn (Request $r) => $r->url() === COTIZAR_URL && ! isset($r['vehicle']['fuel_type_id']));
});

it('elige el máximo descuento bajo el tope y lo persiste SIN tocar el precio', function () {
    // Regresión del doble descuento (2026-08-05): el `fee` de Visred ya viene
    // bonificado. Aplicarle además el % del catálogo cotizaba POR DEBAJO de lo que la
    // compañía cobraba — acá daba 850 cuando el precio real es 1000. La bonificación
    // elegida sigue viajando como `discount_id` a la emisión; lo que no hace es
    // modificar el precio.
    config()->set('visred.max_discount_percent', ['default' => 20]); // tope del productor (sancor → default)

    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('sancor', [coverResult(1, 'rc', 'RC', 1000.0)]),
        'https://visred.test/v1/patrimoniales/vehicles/params/discount*' => Http::response([
            ['value' => '0', 'discount' => 0, 'description' => 'NO BONIFICA'],
            ['value' => 'P', 'discount' => 10, 'description' => 'Bonificación'],
            ['value' => '5', 'discount' => 15, 'description' => 'Bonificación'],
            ['value' => 'O', 'discount' => 30, 'description' => 'Bonificación'], // sobre el tope → se ignora
        ]),
    ]);

    $alt = app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken())['parsed_alternatives'][0];
    expect($alt['precio'])->toBe(1000.0)         // el fee tal cual: NO 850
        ->and($alt['discount_id'])->toBe('5');   // el 15%, NO el 30% (supera el tope)
});

it('sin bonificaciones de la compañía: el fee queda sin tocar y discount_id nulo', function () {
    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('sancor', [coverResult(1, 'rc', 'RC', 1000.0)]),
        'https://visred.test/v1/patrimoniales/vehicles/params/discount*' => Http::response([]),
    ]);

    $alt = app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken())['parsed_alternatives'][0];
    expect($alt['precio'])->toBe(1000.0)
        ->and($alt['discount_id'])->toBeNull();
});

it('es tolerante a FAILURE parcial: devuelve las companies que resolvieron', function () {
    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [
            ['task_id' => 't-ok', 'company_id' => 'sancor'], ['task_id' => 't-fail', 'company_id' => 'rus'],
        ]]),
        'https://visred.test/v1/tasks/t-ok/' => taskSuccess('sancor', [coverResult(1, 'rc', 'RC', 100.0)]),
        'https://visred.test/v1/tasks/t-fail/' => Http::response(['status' => 'FAILURE', 'ready' => true]),
    ]);

    $result = app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken());

    expect($result['parsed_alternatives'])->toHaveCount(1)
        ->and($result['parsed_alternatives'][0]['aseguradora'])->toBe('Sancor');
});

it('respeta el budget: si la task nunca termina, corta y devuelve parcial sin colgarse', function () {
    Http::fake([
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't-slow']]]),
        'https://visred.test/v1/tasks/t-slow/' => Http::response(['status' => 'PENDING', 'ready' => false]),
    ]);

    $result = app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken());

    expect($result['status'])->toBe('FAILURE')
        ->and($result['parsed_alternatives'])->toBe([]);

    // budget=12, interval=2 → no más de 6 sleeps; no loop infinito.
    Sleep::assertSleptTimes(6);
});

it('falla si no hay version_id resuelto en el store', function () {
    $snapshot = RiskSnapshot::factory()->create(); // sin RiskProviderRef

    expect(fn () => app(VisredQuotationProvider::class)->generateAlternatives($snapshot))
        ->toThrow(RuntimeException::class);
});

it('descarta placeholders (sin nombre) pero conserva las active=false vendibles', function () {
    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't-rus', 'company_id' => 'rus']]]),
        'https://visred.test/v1/tasks/t-rus/' => taskSuccess('rus', [
            coverResult(1, 'sigma', 'Sigma', 109135.0),                          // presentable
            coverResult(2, '', '', 114419.0, active: false),                     // placeholder (sin nombre) → se descarta
            coverResult(3, 't37', 'T37 Todo Riesgo', 117730.0, active: false),   // active=false pero con nombre/fee → vendible, se conserva
        ]),
    ]);

    $result = app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken());

    expect($result['parsed_alternatives'])->toHaveCount(2);
    expect(array_column($result['parsed_alternatives'], 'titulo'))->toBe(['Sigma', 'T37 Todo Riesgo']);
    expect($result['parsed_alternatives'][0]['aseguradora'])->toBe('Rio Uruguay'); // company_id → nombre
});

it('E2E: ApiQuoteResolution con Visred real persiste alternativas + provider ref', function () {
    Queue::fake([NotifyClientQuoteReady::class]);

    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't-sancor', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t-sancor/' => taskSuccess('sancor', [
            coverResult(9001, 'todo-riesgo-c', 'Todo Riesgo C', 78450.0),
            coverResult(9002, 'rc', 'Responsabilidad Civil', 12000.0),
        ]),
    ]);

    // Flip al proveedor real (override del StubQuotationProvider que bindea TestCase).
    app()->bind(QuotationProvider::class, VisredQuotationProvider::class);

    $snapshot = snapshotWithToken('TOKEN_E2E');
    $conversation = Conversation::factory()->create();
    $quote = Quote::create([
        'session_uuid' => '11111111-1111-1111-1111-111111111111',
        'risk_snapshot_id' => $snapshot->id,
        'conversation_id' => $conversation->id,
        'status' => 'pending',
    ]);

    app(ApiQuoteResolution::class)->resolve($quote, $snapshot);

    $quote->refresh()->load('alternatives', 'providerRef');

    expect($quote->status)->toBe('processed')
        ->and($quote->alternatives)->toHaveCount(2)
        ->and($quote->alternatives->pluck('aseguradora')->unique()->all())->toBe(['Sancor'])
        ->and($quote->providerRef->external_quote_id)->toBe('9001'); // quotation_result_id de la 1ra alt

    Queue::assertPushed(NotifyClientQuoteReady::class);
});
