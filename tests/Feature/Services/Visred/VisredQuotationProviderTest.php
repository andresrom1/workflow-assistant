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

function coverResult(int $id, string $coverId, string $coverName, float $fee, bool $active = true): array
{
    return [
        'quotation_result_id' => $id,
        'cover' => ['id' => $coverId, 'additional_code' => 'def', 'name' => $coverName, 'description' => "Detalle {$coverName}", 'static_fee' => null, 'active' => $active],
        'validity_id' => 'semestral',
        'fee' => $fee,
        'installments' => 12,
        'franchise' => null,
        'insured_amount' => 14_200_000,
        'payment_method_id' => 'todos',
        'features' => [['id' => 'granizo', 'name' => 'Granizo', 'description' => 'Cubierto.']],
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
            coverResult(7386, 'todo-riesgo-c', 'Todo Riesgo C', 78450.0),
            coverResult(7387, 'terceros-completo', 'Terceros Completo', 42000.0),
        ]),
        'https://visred.test/v1/tasks/t-sancor/' => taskSuccess('sancor', [
            coverResult(9001, 'rc', 'Responsabilidad Civil', 12000.0),
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

    // No-GNC: el campo NO se manda (Visred lo exige solo para 'gnc').
    app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken('T_NAFTA', ['combustible' => 'nafta']));
    Http::assertSent(fn (Request $r) => $r->url() === COTIZAR_URL
        && $r['vehicle']['fuel_type_id'] === 'nafta'
        && ! isset($r['vehicle']['insured_amount_fuel']));
});

it('manda el DNI placeholder configurable cuando el cliente aún no lo cargó', function () {
    config()->set('visred.default_holder_dni', '30000000');

    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('sancor', [coverResult(1, 'rc', 'RC', 1.0)]),
    ]);

    // El DNI real se captura recién en checkout. En cotización mandamos un placeholder
    // para no perder compañías que exigen person_holder (San Cristóbal/Galicia); se
    // sobrescribe con el real al emitir. Ver docs/v2/08 §2.2.
    app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken('TOKEN_X', ['dni' => null]));

    Http::assertSent(fn (Request $r) => $r->url() === COTIZAR_URL
        && $r['person_holder']['document_number'] === '30000000');
});

it('omite person_holder si ni el snapshot ni el config traen DNI (evita el 400)', function () {
    config()->set('visred.default_holder_dni', '');

    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('sancor', [coverResult(1, 'rc', 'RC', 1.0)]),
    ]);

    app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken('TOKEN_X', ['dni' => null]));

    Http::assertSent(fn (Request $r) => $r->url() === COTIZAR_URL && ! isset($r['person_holder']));
});

it('mapea el combustible de dominio al fuel_type_id real del catálogo (nafta→nafta, diésel→diesel)', function () {
    Http::fake([
        COMPANIES_URL => companiesResponse(),
        DISCOUNT_URL => Http::response([]),
        COTIZAR_URL => Http::response(['tasks_list' => [['task_id' => 't1', 'company_id' => 'sancor']]]),
        'https://visred.test/v1/tasks/t1/' => taskSuccess('sancor', [coverResult(1, 'rc', 'RC', 1.0)]),
    ]);

    app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken('T1', ['combustible' => 'nafta']));
    Http::assertSent(fn (Request $r) => $r->url() === COTIZAR_URL && $r['vehicle']['fuel_type_id'] === 'nafta');

    app(VisredQuotationProvider::class)->generateAlternatives(snapshotWithToken('T2', ['combustible' => 'Diésel']));
    Http::assertSent(fn (Request $r) => $r->url() === COTIZAR_URL && $r['vehicle']['fuel_type_id'] === 'diesel');
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

it('aplica el máximo descuento dentro del tope del productor y persiste el discount_id', function () {
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
    expect($alt['precio'])->toBe(850.0)          // 1000 * (1 - 15/100): 15% es el máx ≤ 20%
        ->and($alt['discount_id'])->toBe('5');   // NO el 30% (supera el tope)
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
