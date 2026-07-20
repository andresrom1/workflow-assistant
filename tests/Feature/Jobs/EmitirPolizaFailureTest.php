<?php

use App\Exceptions\Visred\VisredApiException;
use App\Jobs\EmitirPoliza;
use App\Jobs\NotifyClientEmissionFailed;
use App\Mail\EmisionFallidaMail;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\Quote;
use App\Models\RiskSnapshot;
use App\Services\PolizaEmisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

uses(RefreshDatabase::class);

/**
 * @return array{0: Quote, 1: CheckoutSession}
 */
function emisionFallidaFixture(): array
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
        'aseguradora' => 'Sancor',
        'titulo' => 'Todo Riesgo',
        'descripcion' => 'Cobertura full',
        'normalized_grade' => 'all_risk',
        'precio' => 78450.0,
        'moneda' => 'ARS',
        'marketing_title' => 'Sancor - Todo Riesgo',
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

it('un 400 de Visred hace fail-fast: handle() no relanza (sin $this->job no dispara failed(), pero no hay retry)', function () {
    [$quote, $session] = emisionFallidaFixture();

    $service = $this->mock(PolizaEmisionService::class);
    $service->shouldReceive('emitir')
        ->once() // una sola llamada: no hay reintento dentro de este mismo handle()
        ->andThrow(new VisredApiException('Error de validación.', 400, 'validation_error'));

    // handle() llamado directo (sin pasar por la queue): $this->fail() con
    // $this->job === null solo retorna temprano — lo que importa acá es que la
    // excepción NO se relanza (a diferencia del 503), que es la señal que usa la
    // queue real para no reintentar.
    (new EmitirPoliza($quote->id, $session->id))->handle($service);

    expect(true)->toBeTrue(); // llegar hasta acá sin excepción es la aserción
});

it('un error no-400 (p. ej. 503) SÍ relanza — es la señal que la queue real usa para reintentar', function () {
    [$quote, $session] = emisionFallidaFixture();

    $service = $this->mock(PolizaEmisionService::class);
    $service->shouldReceive('emitir')
        ->once()
        ->andThrow(new VisredApiException('No se pudo conectar con Visred.', 503, 'external_service_unavailable'));

    expect(fn () => (new EmitirPoliza($quote->id, $session->id))->handle($service))
        ->toThrow(VisredApiException::class);
});

it('failed() avisa al equipo primero, después al cliente, y marca el quote como emision_fallida', function () {
    Mail::fake();
    Bus::fake([NotifyClientEmissionFailed::class]);
    [$quote, $session] = emisionFallidaFixture();

    $exception = new VisredApiException(
        'Error de validación.',
        400,
        'validation_error',
        ['non_field_errors' => ['Debes usar el mismo document_number que en la cotización']],
    );

    (new EmitirPoliza($quote->id, $session->id))->failed($exception);

    expect($quote->refresh()->status)->toBe('emision_fallida');

    Mail::assertQueued(EmisionFallidaMail::class, function (EmisionFallidaMail $mail) use ($quote) {
        return $mail->quote->id === $quote->id
            && $mail->error['status'] === 400
            && $mail->error['error_code'] === 'validation_error'
            && $mail->error['field_errors']['non_field_errors'][0] === 'Debes usar el mismo document_number que en la cotización';
    });

    Bus::assertDispatched(NotifyClientEmissionFailed::class, function (NotifyClientEmissionFailed $job) use ($quote) {
        $quoteId = (fn () => $this->quoteId)->call($job);

        return $quoteId === $quote->id;
    });
});

it('failed() con una excepción genérica (no VisredApiException) igual avisa y marca el quote', function () {
    Mail::fake();
    Bus::fake([NotifyClientEmissionFailed::class]);
    [$quote, $session] = emisionFallidaFixture();

    (new EmitirPoliza($quote->id, $session->id))->failed(new RuntimeException('emisión no exitosa'));

    expect($quote->refresh()->status)->toBe('emision_fallida');

    Mail::assertQueued(EmisionFallidaMail::class, function (EmisionFallidaMail $mail) {
        return $mail->error['status'] === null
            && $mail->error['error_code'] === null
            && $mail->error['message'] === 'emisión no exitosa';
    });

    Bus::assertDispatched(NotifyClientEmissionFailed::class);
});

it('failed() NO pisa el estado ni avisa al cliente si la póliza ya se había emitido', function () {
    Mail::fake();
    Bus::fake([NotifyClientEmissionFailed::class]);
    [$quote, $session] = emisionFallidaFixture();

    // La emisión salió bien y lo que reventó fue algo posterior (persistir
    // documentos, encolar el reintento de captura).
    $quote->update(['status' => 'poliza_emitida']);

    (new EmitirPoliza($quote->id, $session->id))->failed(new RuntimeException('falló guardar un PDF'));

    expect($quote->refresh()->status)->toBe('poliza_emitida');
    Mail::assertNothingQueued();
    Bus::assertNotDispatched(NotifyClientEmissionFailed::class);
});

it('failed() no revienta si el quote o la sesión ya no existen', function () {
    Mail::fake();
    Bus::fake([NotifyClientEmissionFailed::class]);

    (new EmitirPoliza(999999, 999999))->failed(new RuntimeException('lo que sea'));

    Mail::assertNothingQueued();
    Bus::assertNotDispatched(NotifyClientEmissionFailed::class);
});
