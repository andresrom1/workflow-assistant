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
use Illuminate\Support\Facades\Bus;
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
    for ($i = 0; $i < $photoCount; $i++) {
        $path = "checkout/{$quote->id}/photos/photo_{$i}.jpg";
        InspectionPhoto::create([
            'quote_id' => $quote->id,
            'photo_key' => "slot_{$i}",
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

beforeEach(function () {
    Storage::fake('r2');
    Bus::fake();
    Mail::fake();
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
