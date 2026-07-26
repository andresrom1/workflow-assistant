<?php

use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon\Carbon::setTestNow();
});

// El test que atrapa el off-by-one-day: la app corre en UTC pero el día calendario que le importa
// al negocio es el argentino. A las 02:00 UTC todavía es el día anterior en Argentina.
it('endOfBusinessDay cierra el día calendario argentino, no el UTC', function (): void {
    // 26/07 02:00 UTC = 25/07 23:00 ART → tiene que vencer al cerrar el 25 en Argentina.
    $fin = Quote::endOfBusinessDay(Carbon\Carbon::parse('2026-07-26 02:00:00', 'UTC'));

    expect($fin->toDateTimeString())->toBe('2026-07-26 02:59:59');
});

it('endOfBusinessDay al mediodía cierra ese mismo día', function (): void {
    // 26/07 15:00 UTC = 26/07 12:00 ART → cierra al terminar el 26 en Argentina.
    $fin = Quote::endOfBusinessDay(Carbon\Carbon::parse('2026-07-26 15:00:00', 'UTC'));

    expect($fin->toDateTimeString())->toBe('2026-07-27 02:59:59');
});

it('isVigente distingue futuro, pasado y sin vencimiento', function (): void {
    expect(Quote::factory()->create(['expires_at' => now()->addHour()])->isVigente())->toBeTrue()
        ->and(Quote::factory()->create(['expires_at' => now()->subHour()])->isVigente())->toBeFalse()
        ->and(Quote::factory()->create(['expires_at' => null])->isVigente())->toBeFalse();
});

it('el scope vigente filtra las vencidas y las que no vencen', function (): void {
    $vigente = Quote::factory()->create(['expires_at' => now()->addHour()]);
    Quote::factory()->create(['expires_at' => now()->subHour()]);
    Quote::factory()->create(['expires_at' => null]);

    expect(Quote::vigente()->pluck('id')->all())->toBe([$vigente->id]);
});

it('ensurePublicToken genera un token de 16 caracteres', function (): void {
    $token = Quote::factory()->create(['public_token' => null])->ensurePublicToken();

    expect($token)->toHaveLength(16);
});

// Un link que ya se le mandó al cliente no puede romperse porque el agente vuelva a presentar.
it('ensurePublicToken es idempotente', function (): void {
    $quote = Quote::factory()->create(['public_token' => null]);

    expect($quote->ensurePublicToken())->toBe($quote->ensurePublicToken())
        ->and($quote->fresh()->public_token)->toBe($quote->public_token);
});

it('los tokens no salen en la serialización del modelo', function (): void {
    $quote = Quote::factory()->create(['public_token' => 'abcdefghijklmnop', 'checkout_token' => 'xyz123']);

    expect($quote->toArray())->not->toHaveKeys(['public_token', 'checkout_token']);
});

it('presentedPair devuelve null cuando nunca se presentó', function (): void {
    expect(Quote::factory()->create()->presentedPair())->toBeNull();
});

it('presentedPair respeta el orden y engancha cada razón con su alternativa', function (): void {
    $quote = Quote::factory()->create([
        'presented_alternative_ids' => [42, 17],
        'presentation_reasons' => ['42' => 'La recomendada', '17' => 'La alternativa'],
    ]);

    expect($quote->presentedPair())->toBe([
        'principal' => ['id' => 42, 'razon' => 'La recomendada'],
        'segunda' => ['id' => 17, 'razon' => 'La alternativa'],
    ]);
});

it('presentedPair tolera que falte una razón', function (): void {
    $quote = Quote::factory()->create([
        'presented_alternative_ids' => [42, 17],
        'presentation_reasons' => ['42' => 'Solo esta tiene razón'],
    ]);

    expect($quote->presentedPair()['segunda'])->toBe(['id' => 17, 'razon' => null]);
});
