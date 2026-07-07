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
    ]));

    $conversation->refresh();
    $buttons = $conversation->metadata['pending_interactive']['buttons'];

    expect(mb_strlen($buttons[0]['title']))->toBeLessThanOrEqual(20);
});
