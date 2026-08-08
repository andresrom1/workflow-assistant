<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Models\Conversation;
use App\Models\CoveragePreference;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * El nivel de cobertura solo no alcanza para elegir bien: "terceros completo" y "terceros
 * completo con granizo" se guardaban idénticos (`preference: "C"`), y el closer terminaba
 * ofreciendo la más barata del nivel, que es justo la que no trae granizo.
 */
function conversacionConVehiculo(string $patente = 'AB123CD'): Conversation
{
    $customer = Customer::factory()->create();
    Vehicle::factory()->create(['customer_id' => $customer->id, 'patente' => $patente]);

    return Conversation::factory()->create(['customer_id' => $customer->id]);
}

it('persiste las coberturas que pidió el cliente además del nivel', function () {
    $conversation = conversacionConVehiculo();

    $result = app(WhatsAppAdapter::class)->handleToolCall([
        'coverage_code' => 'C',
        'patente' => 'AB123CD',
        'reasoning' => 'Cliente pidió terceros completo con granizo',
        'coberturas_requeridas' => ['Granizo'],
    ], 'coverage_preference', $conversation);

    expect($result['success'])->toBeTrue();

    $preferencia = CoveragePreference::where('conversation_id', $conversation->id)->sole();

    expect($preferencia->preference)->toBe('C')
        ->and($preferencia->metadata['coberturas_requeridas'])->toBe(['Granizo'])
        ->and($preferencia->metadata['reasoning'])->toBe('Cliente pidió terceros completo con granizo');
});

it('deja metadata en null cuando el cliente solo nombró el nivel', function () {
    $conversation = conversacionConVehiculo();

    app(WhatsAppAdapter::class)->handleToolCall([
        'coverage_code' => 'B',
        'patente' => 'AB123CD',
    ], 'coverage_preference', $conversation);

    expect(CoveragePreference::where('conversation_id', $conversation->id)->sole()->metadata)->toBeNull();
});

/**
 * El closer elige las alternativas en el turno en que corre `get_quote`. Si el pedido no viaja
 * en ese output tiene que re-derivarlo del historial, que es donde se perdía.
 */
it('get_quote le recuerda al closer qué coberturas pidió el cliente', function () {
    $conversation = conversacionConVehiculo();

    app(WhatsAppAdapter::class)->handleToolCall([
        'coverage_code' => 'C',
        'patente' => 'AB123CD',
        'reasoning' => 'Pidió granizo',
        'coberturas_requeridas' => ['Granizo', 'Cristales'],
    ], 'coverage_preference', $conversation);

    $quote = Quote::create([
        'session_uuid' => (string) Str::uuid(),
        'risk_snapshot_id' => RiskSnapshot::factory()->create()->id,
        'conversation_id' => $conversation->id,
        'status' => 'processed',
    ]);
    QuoteAlternative::create([
        'quote_id' => $quote->id,
        'aseguradora' => 'Triunfo',
        'titulo' => 'C2',
        'normalized_grade' => 'third_party_complete',
        'precio' => 75878.40,
        'moneda' => 'ARS',
        'features_tags' => ['Granizo', 'Robo Parcial'],
    ]);

    $result = app(WhatsAppAdapter::class)->handleToolCall(
        ['quoteId' => $quote->id],
        'get_quote',
        $conversation
    );

    expect($result['success'])->toBeTrue()
        ->and($result['tool_output'])->toContain('cobertura C')
        ->and($result['tool_output'])->toContain('Granizo, Cristales')
        ->and($result['tool_output'])->toContain('Descartá las alternativas que no las incluyan');
});

it('get_quote no inventa un pedido cuando no hay preferencia registrada', function () {
    $conversation = conversacionConVehiculo();

    $quote = Quote::create([
        'session_uuid' => (string) Str::uuid(),
        'risk_snapshot_id' => RiskSnapshot::factory()->create()->id,
        'conversation_id' => $conversation->id,
        'status' => 'processed',
    ]);

    $result = app(WhatsAppAdapter::class)->handleToolCall(
        ['quoteId' => $quote->id],
        'get_quote',
        $conversation
    );

    expect($result['success'])->toBeTrue()
        ->and($result['tool_output'])->not->toContain('El cliente pidió');
});
