<?php

use App\Models\Conversation;
use App\Models\Poliza;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\Risk;
use App\Models\RiskSnapshot;
use App\Services\PolicyReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{0: Quote, 1: QuoteAlternative, 2: RiskSnapshot}
 */
function quoteWithAlternative(): array
{
    $snapshot = RiskSnapshot::factory()->create();
    $conversation = Conversation::factory()->create();

    $quote = Quote::create([
        'session_uuid' => (string) Str::uuid(),
        'risk_snapshot_id' => $snapshot->id,
        'conversation_id' => $conversation->id,
        'status' => 'checkout_submitted',
    ]);

    $alternative = $quote->alternatives()->create([
        'aseguradora' => 'Sancor', 'titulo' => 'Todo Riesgo', 'descripcion' => 'Full',
        'normalized_grade' => 'all_risk', 'precio' => 1000.0, 'sum_asegurada' => 9_600_000,
        'moneda' => 'ARS', 'marketing_title' => 'Sancor - Todo Riesgo', 'sum_insured_text' => '',
        'features_tags' => [], 'full_details' => [],
    ]);

    return [$quote, $alternative, $snapshot];
}

/**
 * @return array<string, mixed>
 */
function emissionResult(): array
{
    return [
        'task_id' => 't', 'status' => 'SUCCESS', 'presale_id' => 7788,
        'proposal_number' => 'PR-1', 'policy_number' => 'POL-1', 'emission_status' => 'emitida',
        'requires_inspection_after_emission' => false, 'company_id' => 'sancor', 'raw' => [],
    ];
}

it('crea un Risk nuevo y la Poliza-referencia ligada al Quote', function () {
    [$quote, $alternative, $snapshot] = quoteWithAlternative();

    $poliza = app(PolicyReferenceService::class)->materialize($quote, $alternative, emissionResult());

    expect($poliza->quote_id)->toBe($quote->id)
        ->and($poliza->presale_id)->toBe('7788')
        ->and($poliza->numero)->toBe('POL-1')
        ->and($poliza->company)->toBe('Sancor')       // display que MANGO ya conoce
        ->and($poliza->coverage)->toBe('Todo Riesgo')
        ->and($poliza->coverage_detail)->toBe('Full')
        ->and((float) $poliza->sum_asegurada)->toBe(9_600_000.0)  // congelado de la cotización
        ->and((float) $poliza->cuota)->toBe(1000.0)
        ->and($poliza->company_id)->toBe('sancor')
        ->and($poliza->metadata['proposal_number'])->toBe('PR-1');

    expect(Risk::count())->toBe(1)
        ->and($poliza->risk->customer_id)->toBe($snapshot->customer_id);
});

it('es idempotente por quote_id: re-materializar no duplica la Poliza', function () {
    [$quote, $alternative] = quoteWithAlternative();

    app(PolicyReferenceService::class)->materialize($quote, $alternative, emissionResult());
    app(PolicyReferenceService::class)->materialize($quote, $alternative, emissionResult());

    expect(Poliza::where('quote_id', $quote->id)->count())->toBe(1);
});
