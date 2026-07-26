<?php

use App\Models\Conversation;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Repositories\QuoteRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function pendingQuote(): Quote
{
    $snapshot = RiskSnapshot::factory()->create();
    $conversation = Conversation::factory()->create();

    return Quote::create([
        'session_uuid' => (string) Str::uuid(),
        'risk_snapshot_id' => $snapshot->id,
        'conversation_id' => $conversation->id,
        'status' => 'pending',
    ]);
}

/**
 * @param  list<array<string, mixed>>  $alternatives
 * @return array<string, mixed>
 */
function engineResultWith(array $alternatives): array
{
    return [
        'task_id' => 'task-xyz',
        'status' => 'SUCCESS',
        'raw' => ['source' => 'test'],
        'parsed_alternatives' => $alternatives,
    ];
}

/**
 * @return array<string, mixed>
 */
function parsedAlternative(string $externalQuoteId, bool $requiresInspection = false): array
{
    return [
        'external_quote_id' => $externalQuoteId,
        'external_code' => "COVER-{$externalQuoteId}",
        'company_id' => 'sancor',
        'discount_id' => '5',
        'requires_inspection_before_emission' => $requiresInspection,
        'aseguradora' => 'Test Seguros',
        'titulo' => "Cobertura {$externalQuoteId}",
        'descripcion' => 'Detalle',
        'normalized_grade' => 'liability',
        'precio' => 1000.0,
        'moneda' => 'ARS',
        'marketing_title' => 'Test',
        'sum_insured_text' => '',
        'features_tags' => [],
        'full_details' => [],
    ];
}

it('persiste un provider_ref por alternativa con su quotation_result_id + flag de inspección', function () {
    $quote = pendingQuote();

    app(QuoteRepository::class)->saveResults($quote, engineResultWith([
        parsedAlternative('7386', requiresInspection: true),
        parsedAlternative('7387'),
    ]));

    $alternatives = $quote->refresh()->alternatives()->orderBy('id')->get();
    expect($alternatives)->toHaveCount(2);

    expect($alternatives[0]->providerRef->external_quote_id)->toBe('7386')
        ->and($alternatives[0]->providerRef->company_id)->toBe('sancor')
        ->and($alternatives[0]->providerRef->discount_id)->toBe('5')
        ->and($alternatives[0]->providerRef->requires_inspection_before_emission)->toBeTrue()
        ->and($alternatives[1]->providerRef->external_quote_id)->toBe('7387')
        ->and($alternatives[1]->providerRef->company_id)->toBe('sancor')
        ->and($alternatives[1]->providerRef->requires_inspection_before_emission)->toBeFalse();

    // La auditoría per-quote sigue intacta (raw + id de la 1ra).
    expect($quote->providerRef->external_quote_id)->toBe('7386')
        ->and($quote->providerRef->raw_response)->toBe(['source' => 'test']);
});

it('la alternativa elegida en checkout recupera su quotation_result_id', function () {
    $quote = pendingQuote();

    app(QuoteRepository::class)->saveResults($quote, engineResultWith([
        parsedAlternative('7386'),
        parsedAlternative('7387'),
    ]));

    $chosen = $quote->refresh()->alternatives()->orderBy('id')->get()->last();
    $quote->update(['checkout_alternative_id' => $chosen->id]);

    $resolved = $quote->alternatives()
        ->where('id', $quote->checkout_alternative_id)
        ->firstOrFail()
        ->providerRef;

    expect($resolved->external_quote_id)->toBe('7387');
});

it('no escribe el token de proveedor en la tabla de dominio quote_alternatives (ADR-001)', function () {
    expect(Schema::hasColumn('quote_alternatives', 'external_quote_id'))->toBeFalse()
        ->and(Schema::hasColumn('quote_alternatives', 'external_code'))->toBeFalse()
        ->and(Schema::hasColumn('quote_alternatives', 'company_id'))->toBeFalse();
});

it('omite la ref cuando la alternativa no trae quotation_result_id', function () {
    $quote = pendingQuote();

    $sinId = parsedAlternative('');
    app(QuoteRepository::class)->saveResults($quote, engineResultWith([
        $sinId,
        parsedAlternative('7387'),
    ]));

    $alternatives = $quote->refresh()->alternatives()->orderBy('id')->get();
    expect($alternatives[0]->providerRef)->toBeNull()
        ->and($alternatives[1]->providerRef->external_quote_id)->toBe('7387');
});

it('es idempotente: re-ejecutar saveResults deja la elegida resolviendo su ref', function () {
    $quote = pendingQuote();
    $result = engineResultWith([parsedAlternative('7386'), parsedAlternative('7387')]);

    app(QuoteRepository::class)->saveResults($quote, $result);
    app(QuoteRepository::class)->saveResults($quote, $result);

    $alternatives = $quote->refresh()->alternatives()->orderBy('id')->get();
    expect($alternatives)->toHaveCount(2)
        ->and($alternatives[0]->providerRef->external_quote_id)->toBe('7386')
        ->and($alternatives[1]->providerRef->external_quote_id)->toBe('7387');
});

// Los precios de las compañías valen por día calendario argentino. La app corre en UTC, así que
// un endOfDay() sin conversión de zona vencería un día tarde todo lo cotizado entre las 21:00 ART
// y la medianoche.
it('vence al cierre del día argentino, no del UTC', function () {
    // 26/07 02:00 UTC = 25/07 23:00 ART → cierra al terminar el 25 en Argentina.
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-07-26 02:00:00', 'UTC'));

    $quote = pendingQuote();
    app(QuoteRepository::class)->saveResults($quote, engineResultWith([parsedAlternative('7386')]));

    expect($quote->refresh()->expires_at->toDateTimeString())->toBe('2026-07-26 02:59:59');

    Carbon\Carbon::setTestNow();
});

it('una cotización recién guardada queda vigente', function () {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-07-26 15:00:00', 'UTC'));

    $quote = pendingQuote();
    app(QuoteRepository::class)->saveResults($quote, engineResultWith([parsedAlternative('7386')]));

    expect($quote->refresh()->isVigente())->toBeTrue();

    Carbon\Carbon::setTestNow();
});
