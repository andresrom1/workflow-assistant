<?php

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Conversation;
use App\Models\CoveragePreference;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

/**
 * Visred cotiza el mismo cover una vez por medio de pago y el checkout solo procesa tarjeta.
 * Sin filtrar, al closer le llega el mismo plan repetido —con el precio de cupón, más caro—
 * como si fueran opciones distintas.
 */
it('get_quote solo ofrece alternativas que el checkout puede cobrar', function () {
    $conversation = conversacionConVehiculo();

    $quote = Quote::create([
        'session_uuid' => (string) Str::uuid(),
        'risk_snapshot_id' => RiskSnapshot::factory()->create()->id,
        'conversation_id' => $conversation->id,
        'status' => 'processed',
    ]);

    // El mismo producto, tal como lo manda San Cristóbal.
    foreach ([['cbu', 100308], ['tarjeta', 100308], ['cupon', 122971]] as [$medio, $precio]) {
        QuoteAlternative::create([
            'quote_id' => $quote->id,
            'aseguradora' => 'San Cristobal',
            'titulo' => 'C Mega',
            'normalized_grade' => 'third_party_complete_plus',
            'precio' => $precio,
            'moneda' => 'ARS',
            'payment_method_id' => $medio,
        ]);
    }

    $result = app(WhatsAppAdapter::class)->handleToolCall(
        ['quoteId' => $quote->id],
        'get_quote',
        $conversation
    );

    $alternativas = json_decode($result['quotes'], true)['alternatives'];

    expect($alternativas)->toHaveCount(1)
        ->and($alternativas[0]['precio'])->toBe('100308.00');
});

/** Las cotizaciones anteriores a la columna no tienen medio de pago: sus links siguen abriendo. */
it('get_quote conserva las alternativas sin medio de pago', function () {
    $conversation = conversacionConVehiculo();

    $quote = Quote::create([
        'session_uuid' => (string) Str::uuid(),
        'risk_snapshot_id' => RiskSnapshot::factory()->create()->id,
        'conversation_id' => $conversation->id,
        'status' => 'processed',
    ]);
    QuoteAlternative::create([
        'quote_id' => $quote->id,
        'aseguradora' => 'Galicia',
        'titulo' => 'C80',
        'normalized_grade' => 'third_party_complete',
        'precio' => 73106.22,
        'moneda' => 'ARS',
        'payment_method_id' => null,
    ]);

    $result = app(WhatsAppAdapter::class)->handleToolCall(
        ['quoteId' => $quote->id],
        'get_quote',
        $conversation
    );

    expect(json_decode($result['quotes'], true)['alternatives'])->toHaveCount(1);
});

/** Crea una cotización procesada para la conversación, vigente o vencida. */
function cotizacionProcesada(Conversation $conversation, bool $vigente): Quote
{
    return Quote::create([
        'session_uuid' => (string) Str::uuid(),
        'risk_snapshot_id' => RiskSnapshot::factory()->create()->id,
        'conversation_id' => $conversation->id,
        'status' => 'processed',
        'expires_at' => $vigente ? Quote::endOfBusinessDay() : now()->subDays(2),
    ]);
}

/**
 * El cliente puede pedir dos niveles en un mensaje, y entonces la tool se llama dos veces en el
 * mismo turno: la primera resuelve la cotización y la segunda ya no encuentra ninguna pendiente.
 * Eso le hacía decir al agente que no se pudo cotizar ese nivel — falso, y llegó a un cliente.
 */
it('no dice que no se pudo cotizar cuando ya hay una cotización lista', function () {
    $conversation = conversacionConVehiculo();
    cotizacionProcesada($conversation, vigente: true);

    $result = app(WhatsAppAdapter::class)->handleToolCall([
        'coverage_code' => 'D',
        'patente' => 'AB123CD',
    ], 'coverage_preference', $conversation);

    expect($result['success'])->toBeTrue()
        ->and($result['tool_output'])->toContain('Ya hay una cotización lista')
        ->and($result['tool_output'])->not->toContain('no hay una cotización en marcha')
        ->and($result['tool_output'])->not->toContain('derivar a un asesor');
});

/** Los precios valen por el día en que se cotizaron: una vencida no habilita seguir presentando. */
it('una cotización vencida no cuenta como cotización lista', function () {
    $conversation = conversacionConVehiculo();
    cotizacionProcesada($conversation, vigente: false);

    $result = app(WhatsAppAdapter::class)->handleToolCall([
        'coverage_code' => 'D',
        'patente' => 'AB123CD',
    ], 'coverage_preference', $conversation);

    expect($result['tool_output'])->toContain('no hay una cotización en marcha');
});

it('persiste los dos niveles cuando el cliente pide comparar', function () {
    $conversation = conversacionConVehiculo();

    app(WhatsAppAdapter::class)->handleToolCall([
        'coverage_code' => 'D',
        'coverage_codes' => ['C', 'D'],
        'patente' => 'AB123CD',
        'reasoning' => 'Quiere comparar el costo de los dos',
    ], 'coverage_preference', $conversation);

    expect(CoveragePreference::where('conversation_id', $conversation->id)->sole()->metadata['niveles_solicitados'])
        ->toBe(['C', 'D']);
});

it('no guarda la lista de niveles cuando pidió uno solo', function () {
    $conversation = conversacionConVehiculo();

    app(WhatsAppAdapter::class)->handleToolCall([
        'coverage_code' => 'C',
        'coverage_codes' => ['C'],
        'patente' => 'AB123CD',
    ], 'coverage_preference', $conversation);

    expect(CoveragePreference::where('conversation_id', $conversation->id)->sole()->metadata)->toBeNull();
});

it('get_quote le avisa al closer que el cliente pidió comparar dos niveles', function () {
    $conversation = conversacionConVehiculo();

    app(WhatsAppAdapter::class)->handleToolCall([
        'coverage_code' => 'D',
        'coverage_codes' => ['C', 'D'],
        'patente' => 'AB123CD',
    ], 'coverage_preference', $conversation);

    $quote = cotizacionProcesada($conversation, vigente: true);

    $result = app(WhatsAppAdapter::class)->handleToolCall(
        ['quoteId' => $quote->id],
        'get_quote',
        $conversation
    );

    expect($result['tool_output'])->toContain('comparar las coberturas C y D')
        ->and($result['tool_output'])->toContain('una de cada nivel');
});

// ---------------------------------------------------------------------------
// Aviso de espera — sale ANTES de consultar a las compañías
// ---------------------------------------------------------------------------

/**
 * La consulta a las compañías es sincrónica y tarda 25-60s (p50 medido en prod: 25s). Si el
 * cliente tuviera que esperar al texto del LLM, se enteraría de la espera recién cuando ya
 * terminó — el anuncio llegaba invertido. Por eso el aviso es texto fijo y sale por su cuenta.
 */
it('avisa que está consultando antes de llamar a las compañías', function () {
    Bus::fake([SendWhatsAppMessage::class]);
    config()->set('services.whatsapp.phone_number_id', '123456789');

    $conversation = conversacionConVehiculo();
    $conversation->update(['ext_user_id' => 'US.13491208655302741918']);

    Quote::factory()->create([
        'conversation_id' => $conversation->id,
        'status' => 'pending',
    ]);

    app(WhatsAppAdapter::class)->handleToolCall([
        'coverage_code' => 'C',
        'patente' => 'AB123CD',
    ], 'coverage_preference', $conversation);

    Bus::assertDispatched(
        SendWhatsAppMessage::class,
        fn (SendWhatsAppMessage $job): bool => $job->queue === 'whatsapp-outbound'
    );
});

it('no avisa nada cuando no hay una cotización pendiente que disparar', function () {
    Bus::fake([SendWhatsAppMessage::class]);
    config()->set('services.whatsapp.phone_number_id', '123456789');

    $conversation = conversacionConVehiculo();

    app(WhatsAppAdapter::class)->handleToolCall([
        'coverage_code' => 'C',
        'patente' => 'AB123CD',
    ], 'coverage_preference', $conversation);

    Bus::assertNotDispatched(SendWhatsAppMessage::class);
});

/**
 * Con el aviso ya entregado, si el agente vuelve a decir "estoy consultando" el cliente lee
 * dos veces el mismo anuncio y duda de si algo se rompió.
 */
it('le instruye al agente que no repita el anuncio de espera', function () {
    Bus::fake([SendWhatsAppMessage::class]);
    config()->set('services.whatsapp.phone_number_id', '123456789');

    $conversation = conversacionConVehiculo();

    $quote = Quote::factory()->create([
        'conversation_id' => $conversation->id,
        'status' => 'pending',
    ]);

    // Resolución exitosa sin salir a la red: lo que se prueba es qué le dice la tool al
    // agente una vez que la cotización volvió, no el proveedor.
    $quoteService = $this->mock(QuoteService::class);
    $quoteService->shouldReceive('updateSnapshotPreference')->andReturnNull();
    $quoteService->shouldReceive('resolveQuote')->once()->andReturnTrue();

    $result = app(WhatsAppAdapter::class)->handleToolCall([
        'coverage_code' => 'C',
        'patente' => 'AB123CD',
    ], 'coverage_preference', $conversation);

    expect($quote->fresh())->not->toBeNull()
        ->and($result['tool_output'])->toContain('ya se le avisó')
        ->and($result['tool_output'])->toContain('NO se lo repitas');
});
