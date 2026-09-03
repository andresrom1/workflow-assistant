<?php

use App\Enums\InspectionPhotoStatus;
use App\Jobs\EmitirPoliza;
use App\Jobs\NotifyClientCheckoutCompleted;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\InspectionPhoto;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Quote listo para checkout (pending) + alternativa elegida + N fotos temp.
 *
 * `$snapshotDni` null (default) replica el caso más común (cliente identificado
 * solo por teléfono, sin DNI capturado en el chat) — el guard de coincidencia de
 * DNI (ver los tests al final del archivo) no tiene nada contra qué comparar.
 *
 * @return array{0: Quote, 1: list<string>}
 */
function checkoutReadyQuote(int $photoCount = 8, ?string $snapshotDni = null): array
{
    $snapshot = RiskSnapshot::factory()->create(['dni' => $snapshotDni]);
    $conversation = Conversation::factory()->create();

    $quote = Quote::create([
        'session_uuid' => (string) Str::uuid(),
        'risk_snapshot_id' => $snapshot->id,
        'conversation_id' => $conversation->id,
        'status' => 'checkout_pending',
        'checkout_token' => (string) Str::uuid(),
    ]);

    $alternative = $quote->alternatives()->create([
        'aseguradora' => 'Sancor', 'titulo' => 'Todo Riesgo', 'descripcion' => 'Full',
        'normalized_grade' => 'all_risk', 'precio' => 1000.0, 'moneda' => 'ARS',
        'marketing_title' => 'Sancor - Todo Riesgo', 'sum_insured_text' => '',
        'features_tags' => [], 'full_details' => [],
    ]);
    $quote->update(['checkout_alternative_id' => $alternative->id]);

    $paths = [];
    foreach (array_slice(InspectionPhoto::CLAVES, 0, $photoCount) as $key) {
        $path = "checkout/{$quote->id}/photos/photo_{$key}.jpg";
        InspectionPhoto::create([
            'quote_id' => $quote->id,
            'photo_key' => $key,
            'storage_path' => $path,
            'storage_url' => "http://r2/{$path}",
            'status' => InspectionPhotoStatus::Temp,
        ]);
        $paths[] = $path;
    }

    return [$quote, $paths];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checkoutPayload(Quote $quote, array $paths, array $overrides = []): array
{
    return array_replace([
        'checkout_token' => $quote->checkout_token,
        'first_name' => 'Juan', 'last_name' => 'Pérez',
        'dni' => '36356190', 'birthdate' => '1990-01-15',
        'sex_id' => 'M', 'tax_condition_id' => 'CF',
        'email' => 'juan@example.com',
        'phone_prefix' => '351', 'phone_number' => '1234567',
        'domicilio_calle' => 'San Martín', 'domicilio_numero' => '123',
        'domicilio_cp' => '5000', 'domicilio_provincia' => 'Córdoba', 'domicilio_localidad' => 'Córdoba',
        'vehiculo_uso' => 'particular', 'vehiculo_nro_chasis' => 'CHA789', 'vehiculo_nro_motor' => 'MOT456',
        'has_gnc' => true,
        'cc_brand' => 'visa', 'cc_pan' => '4111111111111111', 'cc_expiry' => '12/27',
        'cc_holder_name' => 'JUAN PEREZ', 'cc_holder_dni' => '36356190',
        'photo_ids' => $paths,
    ], $overrides);
}

/**
 * Le cuelga a la alternativa elegida la referencia del proveedor, que es de donde
 * sale la compañía con la que se pide el catálogo de marcas de tarjeta.
 */
function withProviderCompany(Quote $quote, string $companyId): void
{
    $quote->alternatives()->firstOrFail()->providerRef()->create([
        'external_quote_id' => '16543585',
        'company_id' => $companyId,
    ]);
}

beforeEach(function () {
    Storage::fake('r2');
    Bus::fake();
    Mail::fake();

    // El checkout consulta catálogos de Visred (condiciones fiscales, marcas de
    // tarjeta). Sin fake, cada test de submit le pegaría a la red de verdad.
    //
    // El fake se resuelve en cada request contra `$this->cards*` en vez de con un
    // `Http::fake([url => ...])` por test: los stubs se ACUMULAN y el primero que
    // matchea gana, así que un catch-all acá dejaría muertos a los de los tests.
    // Default vacío = sin catálogo, que es como `cc_brand` valida solo el formato.
    $this->cardsBody = [];
    $this->cardsStatus = 200;

    Http::fake(fn ($request) => str_contains($request->url(), '/params/credit-card/')
        ? Http::response($this->cardsBody, $this->cardsStatus)
        : Http::response([]));

    config()->set('visred.base_url', 'https://visred.test');
    Cache::put('visred:access_token', 'TESTTOKEN', 3300);
});

it('persiste los campos del titular partidos + GNC y compone nombre/telefono', function () {
    [$quote, $paths] = checkoutReadyQuote();

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths))
        ->assertOk()
        ->assertJson(['success' => true]);

    $session = CheckoutSession::where('quote_id', $quote->id)->firstOrFail();

    expect($session->first_name)->toBe('Juan')
        ->and($session->last_name)->toBe('Pérez')
        ->and($session->birthdate->format('Y-m-d'))->toBe('1990-01-15')
        ->and($session->sex_id)->toBe('M')
        ->and($session->tax_condition_id)->toBe('CF')
        ->and($session->phone_prefix)->toBe('351')
        ->and($session->phone_number)->toBe('1234567')
        ->and($session->has_gnc)->toBeTrue()
        // Backward-compat para mail/admin:
        ->and($session->nombre)->toBe('Juan Pérez')
        ->and($session->telefono)->toBe('3511234567');

    Bus::assertDispatched(EmitirPoliza::class);
    Bus::assertDispatched(NotifyClientCheckoutCompleted::class);
});

it('rechaza el submit si falta un campo del titular (birthdate)', function () {
    [$quote, $paths] = checkoutReadyQuote();

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths, ['birthdate' => '']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['birthdate']);
});

it('guarda has_gnc=false cuando el vehículo no tiene gas', function () {
    [$quote, $paths] = checkoutReadyQuote();

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths, ['has_gnc' => false]))
        ->assertOk();

    expect(CheckoutSession::where('quote_id', $quote->id)->firstOrFail()->has_gnc)->toBeFalse();
});

// ─── Guard de coincidencia de DNI (cotización vs emisión) ──────────────────────

it('acepta el submit cuando el DNI del checkout coincide con el de la cotización', function () {
    [$quote, $paths] = checkoutReadyQuote(snapshotDni: '36356190');

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths, ['dni' => '36356190']))
        ->assertOk();
});

it('acepta el submit cuando el DNI coincide en dígitos pero difiere en formato (puntos)', function () {
    // Customer::saving normaliza el dni de la cotización a solo-dígitos; el checkout
    // puede recibir el mismo número con puntos — deben reconciliar igual.
    [$quote, $paths] = checkoutReadyQuote(snapshotDni: '36356190');

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths, ['dni' => '36.356.190']))
        ->assertOk();
});

it('rechaza un DNI con formato inválido (texto o cantidad de dígitos imposible)', function () {
    foreach (['abc', '123', '3635619012345'] as $invalido) {
        [$quote, $paths] = checkoutReadyQuote();

        $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths, ['dni' => $invalido]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dni']);
    }
});

it('acepta un CUIT/CUIL de 11 dígitos en el checkout', function () {
    [$quote, $paths] = checkoutReadyQuote();

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths, ['dni' => '20-30123727-7']))
        ->assertOk();
});

it('acepta el submit cuando la cotización tiene el CUIL y el checkout el DNI de la misma persona', function () {
    // El cliente pudo haber dado el CUIL en el chat (identificación con 11 dígitos,
    // Fase 4a) y el DNI a secas en el checkout — misma persona, dígitos distintos.
    // El guard compara por DocumentoIdentidad::clave(), que reduce ambos al DNI.
    [$quote, $paths] = checkoutReadyQuote(snapshotDni: '20717843183');

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths, ['dni' => '71784318']))
        ->assertOk();
});

it('rechaza con 422 en el campo dni cuando el DNI del checkout NO coincide con el de la cotización', function () {
    [$quote, $paths] = checkoutReadyQuote(snapshotDni: '36356190');

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths, ['dni' => '11111111']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['dni']);

    // No se creó ninguna sesión ni se disparó nada — el guard corta antes de la transacción.
    expect(CheckoutSession::where('quote_id', $quote->id)->exists())->toBeFalse();
    Bus::assertNotDispatched(EmitirPoliza::class);
});

it('acepta cualquier DNI cuando la cotización no tenía uno capturado (sin nada contra qué comparar)', function () {
    [$quote, $paths] = checkoutReadyQuote(); // snapshotDni null por default

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths, ['dni' => '99999999']))
        ->assertOk();
});

// ─── Marca de tarjeta: sale del catálogo de la compañía, no de una lista nuestra ───
//
// `cc_brand` viaja verbatim a `payment.credit_card_brand_id`, que del lado de Visred
// es una FK de catálogo POR COMPAÑÍA. Hasta 2026-08-05 se validaba contra una lista
// hardcodeada que incluía `maestro`, que no existe en ningún catálogo de Visred.

it('show() ofrece las marcas de la compañía de la alternativa elegida', function () {
    [$quote] = checkoutReadyQuote();
    withProviderCompany($quote, 'triunfo');

    $this->cardsBody = [
        ['id' => 'kadicard', 'description' => 'Kadicard'],
        ['id' => 'cmr', 'description' => 'C.M.R.'],
    ];

    $this->get(route('checkout.show', $quote->checkout_token))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Checkout/Show')
            ->where('cardBrands', [
                ['ref' => 'kadicard', 'label' => 'Kadicard'],
                ['ref' => 'cmr', 'label' => 'C.M.R.'],
            ])
        );

    Http::assertSent(fn ($request) => str_contains($request->url(), 'company_id=triunfo'));
});

it('rechaza una marca que la compañía no acepta', function () {
    [$quote, $paths] = checkoutReadyQuote();
    withProviderCompany($quote, 'triunfo');

    $this->cardsBody = [['id' => 'visa', 'description' => 'Visa']];

    // `maestro` es el caso real: lo ofrecía nuestra lista y no existe en Visred.
    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths, ['cc_brand' => 'maestro']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cc_brand']);

    expect(CheckoutSession::where('quote_id', $quote->id)->exists())->toBeFalse();
});

it('acepta una marca del catálogo que la lista vieja no tenía', function () {
    [$quote, $paths] = checkoutReadyQuote();
    withProviderCompany($quote, 'triunfo');

    $this->cardsBody = [['id' => 'kadicard', 'description' => 'Kadicard']];

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths, ['cc_brand' => 'kadicard']))
        ->assertOk();

    expect(CheckoutSession::where('quote_id', $quote->id)->first()->cc_brand)->toBe('kadicard');
});

it('valida contra el catálogo global cuando la alternativa no tiene compañía', function () {
    [$quote, $paths] = checkoutReadyQuote(); // sin providerRef

    $this->cardsBody = [['id' => 'visa', 'description' => 'Visa']];

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths, ['cc_brand' => 'maestro']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cc_brand']);

    // Sin compañía se pide el catálogo global: la ruta va sin filtro.
    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'company_id='));
});

it('no bloquea la venta si el catálogo no está disponible', function () {
    [$quote, $paths] = checkoutReadyQuote();
    withProviderCompany($quote, 'triunfo');

    $this->cardsStatus = 500;

    // Sin catálogo (ni de la compañía ni global) se valida solo el formato. Un hipo
    // del endpoint de Visred no puede cortar un checkout.
    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths, ['cc_brand' => 'visa']))
        ->assertOk();
});

// ── Superficie pública de escritura (E2 del plan de seguridad) ───────────────

it('rechaza una foto que pertenece a otra cotización', function () {
    [$quote, $paths] = checkoutReadyQuote();
    [$otra, $pathsAjenos] = checkoutReadyQuote();

    $mezcla = $paths;
    $mezcla[0] = $pathsAjenos[0];

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $mezcla))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photo_ids']);

    expect(CheckoutSession::where('quote_id', $quote->id)->exists())->toBeFalse();
    Bus::assertNotDispatched(EmitirPoliza::class);
});

it('rechaza un path de foto que no existe', function () {
    [$quote, $paths] = checkoutReadyQuote();

    $paths[0] = "checkout/{$quote->id}/photos/photo_inventada.jpg";

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photo_ids']);
});

it('rechaza el envío si repite una foto', function () {
    [$quote, $paths] = checkoutReadyQuote();

    $paths[1] = $paths[0];

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photo_ids']);
});

/**
 * El conteo va sobre el conjunto aceptado y no sobre el total de filas Temp de la
 * cotización: si fuera sobre el total, mandar menos fotos de las pedidas pasaría
 * igual con tal de que en la base hubiera suficientes.
 */
it('no da por cumplido el mínimo mandando menos fotos de las que hay', function () {
    [$quote, $paths] = checkoutReadyQuote(8);

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, array_slice($paths, 0, 3)))
        ->assertStatus(422);

    expect(CheckoutSession::where('quote_id', $quote->id)->exists())->toBeFalse();
});

it('rechaza una photo_key que no está en el catálogo del dominio', function () {
    [$quote] = checkoutReadyQuote();

    $this->postJson(route('checkout.upload-photo'), [
        'checkout_token' => $quote->checkout_token,
        'photo_key' => '../../../etc/passwd',
        // `create` y no `image`: generar una imagen de verdad necesita la extension GD.
        'photo' => UploadedFile::fake()->create('foto.jpg', 100, 'image/jpeg'),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photo_key']);
});

it('corta la ráfaga de envíos con 429', function () {
    [$quote, $paths] = checkoutReadyQuote();
    $payload = checkoutPayload($quote, $paths);

    // El primero pasa y deja el checkout cerrado; del segundo al quinto son 409. El
    // throttle cuenta todos, así que el sexto ya no llega al controller.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson(route('checkout.submit'), $payload);
    }

    $this->postJson(route('checkout.submit'), $payload)->assertStatus(429);
});

it('no registra el token del checkout cuando el submit falla', function () {
    [$quote, $paths] = checkoutReadyQuote();

    Log::spy();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('el mail explotó'));

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths))
        ->assertStatus(500);

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $mensaje, array $contexto): bool => ! array_key_exists('checkout_token', $contexto)
            && ! in_array($quote->checkout_token, $contexto, true)
    );
});

/**
 * La concurrencia real no es reproducible acá: `RefreshDatabase` envuelve el test en una
 * transacción, así que una segunda conexión no ve la cotización y no puede competir por su
 * fila. Lo que sí se puede probar —y es la propiedad que importa— es que el recheck de
 * adentro del lock corta: se cambia el estado justo antes de que corra el `for update`,
 * que es exactamente lo que deja hecho el envío que gana la carrera.
 */
it('el segundo envío simultáneo no duplica emisión, aviso ni mail', function () {
    [$quote, $paths] = checkoutReadyQuote();

    $yaCorrio = false;
    DB::beforeExecuting(function (string $consulta) use ($quote, &$yaCorrio): void {
        if ($yaCorrio || ! str_contains($consulta, 'for update')) {
            return;
        }

        $yaCorrio = true;
        DB::table('quotes')->where('id', $quote->id)->update(['status' => 'checkout_submitted']);
    });

    $this->postJson(route('checkout.submit'), checkoutPayload($quote, $paths))
        ->assertStatus(409);

    expect($yaCorrio)->toBeTrue()
        ->and(CheckoutSession::where('quote_id', $quote->id)->exists())->toBeFalse();

    Bus::assertNotDispatched(EmitirPoliza::class);
    Bus::assertNotDispatched(NotifyClientCheckoutCompleted::class);
});
