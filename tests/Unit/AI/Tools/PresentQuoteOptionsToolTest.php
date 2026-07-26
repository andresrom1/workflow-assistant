<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\AI\Tools\PresentQuoteOptionsTool;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Crea una Quote real (vía el flujo normal de identify_vehicle, con el
 * StubQuotability por defecto de TestCase) y le agrega 2 alternativas.
 *
 * @return array{quote: Quote, alt1: QuoteAlternative, alt2: QuoteAlternative}
 */
function quoteWithTwoAlternatives(): array
{
    $customer = Customer::factory()->create();
    $conversation = Conversation::create([
        'external_conversation_id' => 'thread_present_'.uniqid(),
        'customer_id' => $customer->id,
        'status' => 'active',
        'last_message_at' => now(),
    ]);

    $adapter = app(WhatsAppAdapter::class);
    $result = $adapter->identifyVehicle([
        'patente' => 'AD'.random_int(100, 999).'CC',
        'marca' => 'Peugeot',
        'modelo' => '2008',
        'version' => 'Allure',
        'year' => 2017,
        'combustible' => 'nafta',
        'codigo_postal' => '5000',
        'sessionUuid' => Str::uuid()->toString(),
    ], $conversation);

    $quote = Quote::find($result['quote_id']);

    $alt1 = QuoteAlternative::create([
        'quote_id' => $quote->id,
        'aseguradora' => 'Sancor',
        'normalized_grade' => 'C',
        'precio' => 45230,
        'moneda' => 'ARS',
    ]);
    $alt2 = QuoteAlternative::create([
        'quote_id' => $quote->id,
        'aseguradora' => 'Federación Patronal',
        'normalized_grade' => 'C',
        'precio' => 48900,
        'moneda' => 'ARS',
    ]);

    return ['quote' => $quote, 'alt1' => $alt1, 'alt2' => $alt2, 'conversation' => $conversation];
}

it('builds buttons from domain data with the recommended one first', function () {
    ['quote' => $quote, 'alt1' => $alt1, 'alt2' => $alt2, 'conversation' => $conversation] = quoteWithTwoAlternatives();

    $tool = new PresentQuoteOptionsTool($conversation);
    $result = json_decode($tool->handle(new Request([
        'quote_id' => $quote->id,
        'alternative_ids' => [$alt1->id, $alt2->id],
        'recommended_alternative_id' => $alt2->id,
        'recommended_reason' => 'Cubre granizo y cristales, que fue lo que pediste.',
        'alternative_reason' => 'Sale menos por mes, con menos cobertura.',
    ])), true);

    expect($result['success'])->toBeTrue();

    $conversation->refresh();
    $buttons = $conversation->metadata['pending_interactive']['buttons'];

    expect($buttons)->toHaveCount(3)
        ->and($buttons[0]['id'])->toBe("alt:{$alt2->id}")
        ->and($buttons[0]['title'])->toContain('Federación')
        ->and($buttons[1]['id'])->toBe("alt:{$alt1->id}")
        ->and($buttons[2])->toBe(['id' => 'question', 'title' => 'Tengo una pregunta']);
});

it('rejects an alternative that belongs to another quote', function () {
    ['alt1' => $alt1, 'conversation' => $conversation] = quoteWithTwoAlternatives();
    $otherQuoteData = quoteWithTwoAlternatives();

    $tool = new PresentQuoteOptionsTool($conversation);
    $result = json_decode($tool->handle(new Request([
        'quote_id' => $otherQuoteData['quote']->id,
        'alternative_ids' => [$alt1->id, $otherQuoteData['alt1']->id],
        'recommended_alternative_id' => $alt1->id,
    ])), true);

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe('alternatives_not_found');

    $conversation->refresh();
    expect($conversation->metadata ?? [])->not->toHaveKey('pending_interactive');
});

it('rejects a request with a wrong number of alternative_ids', function () {
    ['quote' => $quote, 'alt1' => $alt1, 'conversation' => $conversation] = quoteWithTwoAlternatives();

    $tool = new PresentQuoteOptionsTool($conversation);
    $result = json_decode($tool->handle(new Request([
        'quote_id' => $quote->id,
        'alternative_ids' => [$alt1->id],
        'recommended_alternative_id' => $alt1->id,
    ])), true);

    expect($result['success'])->toBeFalse()
        ->and($result['error_code'])->toBe('invalid_alternative_count');
});

it('truncates a long aseguradora name to fit the 20-char button limit', function () {
    $customer = Customer::factory()->create();
    $conversation = Conversation::create([
        'external_conversation_id' => 'thread_present_long_'.uniqid(),
        'customer_id' => $customer->id,
        'status' => 'active',
        'last_message_at' => now(),
    ]);

    $adapter = app(WhatsAppAdapter::class);
    $result = $adapter->identifyVehicle([
        'patente' => 'AE'.random_int(100, 999).'CC',
        'marca' => 'Peugeot',
        'modelo' => '2008',
        'version' => 'Allure',
        'year' => 2017,
        'combustible' => 'nafta',
        'codigo_postal' => '5000',
        'sessionUuid' => Str::uuid()->toString(),
    ], $conversation);

    $quote = Quote::find($result['quote_id']);

    $alt1 = QuoteAlternative::create([
        'quote_id' => $quote->id,
        'aseguradora' => 'Un Nombre De Aseguradora Larguísimo',
        'normalized_grade' => 'C',
        'precio' => 45230,
        'moneda' => 'ARS',
    ]);
    $alt2 = QuoteAlternative::create([
        'quote_id' => $quote->id,
        'aseguradora' => 'Sancor',
        'normalized_grade' => 'C',
        'precio' => 48900,
        'moneda' => 'ARS',
    ]);

    $tool = new PresentQuoteOptionsTool($conversation);
    $tool->handle(new Request([
        'quote_id' => $quote->id,
        'alternative_ids' => [$alt1->id, $alt2->id],
        'recommended_alternative_id' => $alt1->id,
        'recommended_reason' => 'Es la que mejor se adapta a lo que contaste.',
        'alternative_reason' => 'Más barata, cubre menos.',
    ]));

    $conversation->refresh();
    $buttons = $conversation->metadata['pending_interactive']['buttons'];

    expect(mb_strlen($buttons[0]['title']))->toBeLessThanOrEqual(20);
});

// ── Persistencia de la presentación ─────────────────────────────────────────

it('persiste qué se presentó, cuál se recomendó y por qué', function () {
    ['quote' => $quote, 'alt1' => $alt1, 'alt2' => $alt2, 'conversation' => $conversation] = quoteWithTwoAlternatives();

    (new PresentQuoteOptionsTool($conversation))->handle(new Request([
        'quote_id' => $quote->id,
        'alternative_ids' => [$alt1->id, $alt2->id],
        'recommended_alternative_id' => $alt2->id,
        'recommended_reason' => 'La franquicia más baja de las dos.',
        'alternative_reason' => 'Sale menos por mes.',
    ]));

    $quote->refresh();

    expect($quote->recommended_alternative_id)->toBe($alt2->id)
        // Recomendada primero, mismo orden que los botones.
        ->and($quote->presented_alternative_ids)->toBe([$alt2->id, $alt1->id])
        ->and($quote->presentation_reasons)->toBe([
            (string) $alt2->id => 'La franquicia más baja de las dos.',
            (string) $alt1->id => 'Sale menos por mes.',
        ])
        ->and($quote->presented_at)->not->toBeNull();
});

it('mintea el token de la vista pública', function () {
    ['quote' => $quote, 'alt1' => $alt1, 'alt2' => $alt2, 'conversation' => $conversation] = quoteWithTwoAlternatives();

    (new PresentQuoteOptionsTool($conversation))->handle(new Request([
        'quote_id' => $quote->id,
        'alternative_ids' => [$alt1->id, $alt2->id],
        'recommended_alternative_id' => $alt1->id,
        'recommended_reason' => 'Razón A.',
        'alternative_reason' => 'Razón B.',
    ]));

    expect($quote->refresh()->public_token)->toHaveLength(16);
});

// Un link que ya se le mandó al cliente no puede romperse porque el agente vuelva a presentar.
it('re-presentar no cambia el token pero sí actualiza las razones', function () {
    ['quote' => $quote, 'alt1' => $alt1, 'alt2' => $alt2, 'conversation' => $conversation] = quoteWithTwoAlternatives();
    $tool = new PresentQuoteOptionsTool($conversation);

    $tool->handle(new Request([
        'quote_id' => $quote->id,
        'alternative_ids' => [$alt1->id, $alt2->id],
        'recommended_alternative_id' => $alt1->id,
        'recommended_reason' => 'Primera razón.',
        'alternative_reason' => 'Primera alternativa.',
    ]));
    $primerToken = $quote->refresh()->public_token;

    $tool->handle(new Request([
        'quote_id' => $quote->id,
        'alternative_ids' => [$alt1->id, $alt2->id],
        'recommended_alternative_id' => $alt2->id,
        'recommended_reason' => 'Segunda razón.',
        'alternative_reason' => 'Segunda alternativa.',
    ]));
    $quote->refresh();

    expect($quote->public_token)->toBe($primerToken)
        ->and($quote->recommended_alternative_id)->toBe($alt2->id)
        ->and($quote->presentation_reasons[(string) $alt2->id])->toBe('Segunda razón.');
});

// ── Guards ──────────────────────────────────────────────────────────────────

it('rechaza una recomendada que no está entre las dos presentadas', function () {
    ['quote' => $quote, 'alt1' => $alt1, 'alt2' => $alt2, 'conversation' => $conversation] = quoteWithTwoAlternatives();

    $result = json_decode((new PresentQuoteOptionsTool($conversation))->handle(new Request([
        'quote_id' => $quote->id,
        'alternative_ids' => [$alt1->id, $alt2->id],
        'recommended_alternative_id' => 999999,
        'recommended_reason' => 'Razón A.',
        'alternative_reason' => 'Razón B.',
    ])), true);

    $quote->refresh();

    expect($result['error_code'])->toBe('recommended_not_in_set')
        ->and($quote->public_token)->toBeNull()
        ->and($quote->presented_alternative_ids)->toBeNull();
});

it('rechaza una presentación sin razones', function (string $recomendada, string $alternativa) {
    ['quote' => $quote, 'alt1' => $alt1, 'alt2' => $alt2, 'conversation' => $conversation] = quoteWithTwoAlternatives();

    $result = json_decode((new PresentQuoteOptionsTool($conversation))->handle(new Request([
        'quote_id' => $quote->id,
        'alternative_ids' => [$alt1->id, $alt2->id],
        'recommended_alternative_id' => $alt1->id,
        'recommended_reason' => $recomendada,
        'alternative_reason' => $alternativa,
    ])), true);

    $quote->refresh();

    expect($result['error_code'])->toBe('missing_reason')
        ->and($quote->public_token)->toBeNull()
        ->and($quote->presented_at)->toBeNull();
})->with([
    'sin la recomendada' => ['', 'Razón B.'],
    'sin la alternativa' => ['Razón A.', ''],
    'solo espacios' => ['   ', '   '],
]);

// Que el modelo escriba de más no justifica romper el turno del cliente.
it('trunca una razón demasiado larga en vez de fallar', function () {
    ['quote' => $quote, 'alt1' => $alt1, 'alt2' => $alt2, 'conversation' => $conversation] = quoteWithTwoAlternatives();

    $result = json_decode((new PresentQuoteOptionsTool($conversation))->handle(new Request([
        'quote_id' => $quote->id,
        'alternative_ids' => [$alt1->id, $alt2->id],
        'recommended_alternative_id' => $alt1->id,
        'recommended_reason' => str_repeat('a', 2000),
        'alternative_reason' => 'Razón B.',
    ])), true);

    $guardada = $quote->refresh()->presentation_reasons[(string) $alt1->id];

    expect($result['success'])->toBeTrue()
        ->and(mb_strlen($guardada))->toBe(600)
        ->and($guardada)->toEndWith('…');
});
