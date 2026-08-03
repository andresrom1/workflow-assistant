<?php

use App\Exceptions\Visred\VisredApiException;
use App\Services\Visred\VisredClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const TOKEN_URL = 'https://visred.test/v1/accounts/token/';
const REFRESH_URL = 'https://visred.test/v1/accounts/token/refresh/';
const PROTECTED_URL = 'https://visred.test/v1/discovery/products/';

const ACCESS_CACHE_KEY = 'visred:access_token';
const REFRESH_CACHE_KEY = 'visred:refresh_token';

beforeEach(function () {
    // Config hermética: el cliente lee config en su constructor, así que la fijamos
    // ANTES de instanciarlo en cada test. Sin tocar el .env real.
    config()->set('visred.base_url', 'https://visred.test');
    config()->set('visred.username', 'svc-user');
    config()->set('visred.password', 'svc-pass');
    config()->set('visred.timeout', 5);
    config()->set('visred.sandbox', false);

    Cache::flush();
});

/**
 * @return array<string, mixed>
 */
function visredFixture(string $name): array
{
    $path = __DIR__.'/../../../Fixtures/Visred/'.$name.'.json';

    return json_decode((string) file_get_contents($path), true);
}

it('hace login, cachea los tokens y adjunta el Bearer + Accept en el request', function () {
    Http::fake([
        TOKEN_URL => Http::response(visredFixture('login_success'), 200),
        PROTECTED_URL => Http::response(visredFixture('protected_success'), 200),
    ]);

    $result = (new VisredClient)->get('/v1/discovery/products/');

    expect($result)->toBe(visredFixture('protected_success'));

    // Login con credenciales de servicio.
    Http::assertSent(fn (Request $r) => $r->url() === TOKEN_URL
        && $r->method() === 'POST'
        && $r['username'] === 'svc-user'
        && $r['password'] === 'svc-pass');

    // El request protegido llevó el access del login + headers correctos.
    Http::assertSent(fn (Request $r) => $r->url() === PROTECTED_URL
        && $r->hasHeader('Authorization', 'Bearer ACCESS_TOKEN_1')
        && $r->hasHeader('Accept', 'application/json'));

    // Tokens cacheados server-side (no en estado de instancia).
    expect(Cache::get(ACCESS_CACHE_KEY))->toBe('ACCESS_TOKEN_1')
        ->and(Cache::get(REFRESH_CACHE_KEY))->toBe('REFRESH_TOKEN_1');
});

it('reutiliza el access cacheado sin volver a loguear', function () {
    Cache::put(ACCESS_CACHE_KEY, 'CACHED_ACCESS', 3300);
    Http::fake([PROTECTED_URL => Http::response(visredFixture('protected_success'), 200)]);

    (new VisredClient)->get('/v1/discovery/products/');

    Http::assertNotSent(fn (Request $r) => $r->url() === TOKEN_URL);
    Http::assertSent(fn (Request $r) => $r->url() === PROTECTED_URL
        && $r->hasHeader('Authorization', 'Bearer CACHED_ACCESS'));
});

it('ante un 401 refresca el token y reintenta una sola vez', function () {
    Cache::put(ACCESS_CACHE_KEY, 'OLD_ACCESS', 3300);
    Cache::put(REFRESH_CACHE_KEY, 'OLD_REFRESH', 72000);

    Http::fake([
        REFRESH_URL => Http::response(visredFixture('refresh_success'), 200),
        PROTECTED_URL => Http::sequence()
            ->push(visredFixture('error_401'), 401)
            ->push(visredFixture('protected_success'), 200),
    ]);

    $result = (new VisredClient)->get('/v1/discovery/products/');

    expect($result)->toBe(visredFixture('protected_success'));

    // Refrescó con el refresh cacheado, sin caer a re-login.
    Http::assertSent(fn (Request $r) => $r->url() === REFRESH_URL && $r['refresh'] === 'OLD_REFRESH');
    Http::assertNotSent(fn (Request $r) => $r->url() === TOKEN_URL);

    // El reintento llevó el access nuevo, y quedó cacheado.
    Http::assertSent(fn (Request $r) => $r->url() === PROTECTED_URL
        && $r->hasHeader('Authorization', 'Bearer ACCESS_TOKEN_2'));
    expect(Cache::get(ACCESS_CACHE_KEY))->toBe('ACCESS_TOKEN_2');

    // Reintentó UNA sola vez: 2 hits al protegido (original + reintento).
    expect(Http::recorded(fn (Request $r) => $r->url() === PROTECTED_URL))->toHaveCount(2);
});

it('si el refresh falla, re-loguea con credenciales de servicio y reintenta', function () {
    Cache::put(ACCESS_CACHE_KEY, 'OLD_ACCESS', 3300);
    Cache::put(REFRESH_CACHE_KEY, 'STALE_REFRESH', 72000);

    Http::fake([
        REFRESH_URL => Http::response(visredFixture('error_401'), 401),
        TOKEN_URL => Http::response(visredFixture('login_success'), 200),
        PROTECTED_URL => Http::sequence()
            ->push(visredFixture('error_401'), 401)
            ->push(visredFixture('protected_success'), 200),
    ]);

    $result = (new VisredClient)->get('/v1/discovery/products/');

    expect($result)->toBe(visredFixture('protected_success'));

    Http::assertSent(fn (Request $r) => $r->url() === REFRESH_URL && $r['refresh'] === 'STALE_REFRESH');
    Http::assertSent(fn (Request $r) => $r->url() === TOKEN_URL && $r['username'] === 'svc-user');
    Http::assertSent(fn (Request $r) => $r->url() === PROTECTED_URL
        && $r->hasHeader('Authorization', 'Bearer ACCESS_TOKEN_1'));
});

it('normaliza el envelope de error de Visred a VisredApiException', function (int $status, string $expectedCode) {
    Cache::put(ACCESS_CACHE_KEY, 'VALID_ACCESS', 3300);
    Http::fake([PROTECTED_URL => Http::response(visredFixture("error_{$status}"), $status)]);

    $client = new VisredClient;

    try {
        $client->get('/v1/discovery/products/');
        $this->fail("Se esperaba VisredApiException para el status {$status}.");
    } catch (VisredApiException $e) {
        expect($e->status())->toBe($status)
            ->and($e->errorCode())->toBe($expectedCode);
    }
})->with([
    'validation_error (400)' => [400, 'validation_error'],
    'permission_denied (403)' => [403, 'permission_denied'],
    'not_found (404)' => [404, 'not_found'],
    'conflict (409)' => [409, 'conflict'],
    'external_service_unavailable (503)' => [503, 'external_service_unavailable'],
]);

it('mapea un 401 persistente a VisredApiException tras refrescar y reintentar', function () {
    Cache::put(ACCESS_CACHE_KEY, 'OLD_ACCESS', 3300);
    Cache::put(REFRESH_CACHE_KEY, 'OLD_REFRESH', 72000);

    Http::fake([
        REFRESH_URL => Http::response(visredFixture('refresh_success'), 200),
        PROTECTED_URL => Http::response(visredFixture('error_401'), 401), // siempre 401
    ]);

    $client = new VisredClient;

    try {
        $client->get('/v1/discovery/products/');
        $this->fail('Se esperaba VisredApiException 401.');
    } catch (VisredApiException $e) {
        expect($e->status())->toBe(401)
            ->and($e->errorCode())->toBe('not_authenticated');
    }

    // Intentó refrescar y reintentó una vez (2 hits), luego se rindió — sin loop.
    Http::assertSent(fn (Request $r) => $r->url() === REFRESH_URL);
    expect(Http::recorded(fn (Request $r) => $r->url() === PROTECTED_URL))->toHaveCount(2);
});

it('expone field_errors por campo en el error de validación', function () {
    Cache::put(ACCESS_CACHE_KEY, 'VALID_ACCESS', 3300);
    $cotizarUrl = 'https://visred.test/v1/patrimoniales/vehicles/cotizar/';
    Http::fake([$cotizarUrl => Http::response(visredFixture('error_400'), 400)]);

    try {
        (new VisredClient)->post('/v1/patrimoniales/vehicles/cotizar/', ['product_id' => 'auto']);
        $this->fail('Se esperaba VisredApiException 400.');
    } catch (VisredApiException $e) {
        expect($e->errorCode())->toBe('validation_error')
            ->and($e->fieldErrors())->toBe([
                'product_id' => ['Requerido.'],
                'vehicle' => ['El año es inválido.'],
            ]);
    }
});

it('aplana los field_errors de serializers anidados al pasar por el cliente', function () {
    // Los serializers anidados de Visred (`payment`, `person_holder`) y los `many=True`
    // devuelven dicts, no `dict[str, list[str]]`. Antes se perdía el mensaje entero y
    // llegaba `['payment' => ['']]` — ver bitácora 2026-08-03.
    Cache::put(ACCESS_CACHE_KEY, 'VALID_ACCESS', 3300);
    $emitirUrl = 'https://visred.test/v1/patrimoniales/vehicles/emitir/';
    Http::fake([$emitirUrl => Http::response(visredFixture('error_400_nested'), 400)]);

    try {
        (new VisredClient)->post('/v1/patrimoniales/vehicles/emitir/', ['quotation_result_id' => 1]);
        $this->fail('Se esperaba VisredApiException 400.');
    } catch (VisredApiException $e) {
        expect($e->fieldErrors())->toBe([
            'payment.credit_card_brand_id' => ['Invalid pk "naranja" - object does not exist.'],
            'person_holder.document_number' => ['Debes usar el mismo document_number que en la cotización.'],
            'inspections.1.image_base64' => ['Requerido.'],
            'product_id' => ['Requerido.'],
        ]);
    }
});

it('NO envía X-Mock-Scenario fuera de sandbox', function () {
    config()->set('visred.sandbox', false);
    Cache::put(ACCESS_CACHE_KEY, 'VALID_ACCESS', 3300);
    Http::fake([PROTECTED_URL => Http::response(visredFixture('protected_success'), 200)]);

    (new VisredClient)->get('/v1/discovery/products/', [], 'error_400');

    Http::assertSent(fn (Request $r) => $r->url() === PROTECTED_URL && ! $r->hasHeader('X-Mock-Scenario'));
});

it('envía X-Mock-Scenario en sandbox', function () {
    config()->set('visred.sandbox', true);
    Cache::put(ACCESS_CACHE_KEY, 'VALID_ACCESS', 3300);
    Http::fake([PROTECTED_URL => Http::response(visredFixture('protected_success'), 200)]);

    (new VisredClient)->get('/v1/discovery/products/', [], 'success');

    Http::assertSent(fn (Request $r) => $r->url() === PROTECTED_URL
        && $r->hasHeader('X-Mock-Scenario', 'success'));
});

it('traduce una falla de red a VisredApiException 503', function () {
    Cache::put(ACCESS_CACHE_KEY, 'VALID_ACCESS', 3300);
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    try {
        (new VisredClient)->get('/v1/discovery/products/');
        $this->fail('Se esperaba VisredApiException por falla de red.');
    } catch (VisredApiException $e) {
        expect($e->status())->toBe(503)
            ->and($e->errorCode())->toBe('external_service_unavailable');
    }
});
