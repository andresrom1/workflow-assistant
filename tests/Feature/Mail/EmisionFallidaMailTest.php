<?php

use App\Exceptions\Visred\VisredApiException;
use App\Jobs\EmitirPoliza;
use App\Jobs\NotifyClientEmissionFailed;
use App\Mail\EmisionFallidaMail;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * `Mail::fake()` NO renderiza el blade, así que un error de plantilla pasaba la
 * suite en verde — está anotado en el ROADMAP. Estos tests fuerzan `render()`:
 * el blade hace `implode(', ', $messages)` sobre el segundo nivel de
 * `field_errors`, y ahí revienta cualquier shape que no sea `list<string>`.
 *
 * @return array{0: Quote, 1: CheckoutSession}
 */
function emisionFallidaMailFixture(): array
{
    $snapshot = RiskSnapshot::factory()->create();
    $conversation = Conversation::factory()->create();

    $quote = Quote::create([
        'session_uuid' => (string) Str::uuid(),
        'risk_snapshot_id' => $snapshot->id,
        'conversation_id' => $conversation->id,
        'status' => 'checkout_submitted',
        'checkout_token' => (string) Str::uuid(),
    ]);

    $alternative = $quote->alternatives()->create([
        'aseguradora' => 'Triunfo',
        'titulo' => 'Responsabilidad Civil',
        'descripcion' => 'Cobertura básica',
        'normalized_grade' => 'third_party',
        'precio' => 24660.0,
        'moneda' => 'ARS',
        'marketing_title' => 'Triunfo A',
        'sum_insured_text' => '',
        'features_tags' => [],
        'full_details' => [],
    ]);

    $session = CheckoutSession::create([
        'quote_id' => $quote->id,
        'quote_alternative_id' => $alternative->id,
        'status' => 'submitted',
        'nombre' => 'Juan Perez',
        'dni' => '36356190',
        'email' => 'juan@example.com',
        'telefono' => '3511234567',
        'submitted_at' => now(),
    ]);

    return [$quote, $session];
}

function emisionFallidaMailable(mixed $fieldErrors): EmisionFallidaMail
{
    [$quote, $session] = emisionFallidaMailFixture();

    return new EmisionFallidaMail($quote, $session, [
        'message' => 'Error de validación.',
        'status' => 400,
        'error_code' => 'validation_error',
        'field_errors' => $fieldErrors,
    ]);
}

it('el mail al equipo muestra el subcampo y el mensaje reales de un error anidado de Visred', function () {
    Mail::fake();
    Bus::fake([NotifyClientEmissionFailed::class]);
    [$quote, $session] = emisionFallidaMailFixture();

    // Cadena completa: respuesta cruda de DRF → normalizador → job → mailable → blade.
    $response = new Response(new Psr7Response(400, [], (string) json_encode([
        'success' => false,
        'error' => [
            'message' => 'Error de validación.',
            'code' => 'validation_error',
            'field_errors' => [
                'payment' => ['credit_card_brand_id' => ['Invalid pk "naranja" - object does not exist.']],
            ],
        ],
    ])));

    (new EmitirPoliza($quote->id, $session->id))->failed(VisredApiException::fromResponse($response));

    Mail::assertQueued(EmisionFallidaMail::class, function (EmisionFallidaMail $mail) {
        $html = $mail->render();

        expect($html)->toContain('payment.credit_card_brand_id')
            ->and($html)->toContain('Invalid pk &quot;naranja&quot; - object does not exist.');

        return true;
    });
});

it('renderiza varios mensajes en un mismo campo separados por coma', function () {
    $html = emisionFallidaMailable(['dni' => ['Requerido.', 'Debe tener 8 dígitos.']])->render();

    expect($html)->toContain('Requerido., Debe tener 8 dígitos.');
});

it('renderiza sin field_errors', function (mixed $fieldErrors) {
    $html = emisionFallidaMailable($fieldErrors)->render();

    expect($html)->toContain('Error de validación.')
        ->and($html)->not->toContain('<strong>payment');
})->with([
    'null' => [null],
    'array vacío' => [[]],
]);
