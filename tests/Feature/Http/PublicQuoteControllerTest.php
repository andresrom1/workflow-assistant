<?php

use App\Jobs\SendWhatsAppMessage;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Cotización con dos alternativas, sembrada con datos personales bien distintivos para que los
 * asserts de filtración no pasen por vacuidad.
 */
function cotizacionPublica(array $atributos = []): Quote
{
    $vehicle = Vehicle::factory()->create(['patente' => 'ZZZ999']);
    $customer = Customer::factory()->create(['name' => 'Nombre Inconfundible']);
    $snapshot = RiskSnapshot::factory()->create([
        'vehicle_id' => $vehicle->id,
        'customer_id' => $customer->id,
        'dni' => '11111111',
        'codigo_postal' => '7654',
        'marca' => 'Peugeot',
        'modelo' => '2008',
        'version' => '1.6 Active',
        'year' => 2017,
        'combustible' => 'gnc',
    ]);

    $quote = Quote::factory()->create(array_merge([
        'risk_snapshot_id' => $snapshot->id,
        'public_token' => 'abcdefghijklmnop',
    ], $atributos));

    QuoteAlternative::factory()->conCoberturas([])->create([
        'quote_id' => $quote->id,
        'aseguradora' => 'Galicia',
        'titulo' => 'Todo Riesgo Franquicia 4%',
        'precio' => 90317.04,
        'normalized_grade' => 'all_risk',
    ]);
    QuoteAlternative::factory()->conCoberturas(['Inundación'])->create([
        'quote_id' => $quote->id,
        'aseguradora' => 'Triunfo',
        'titulo' => 'D3 - Todo Riesgo Franquicia 10%',
        'precio' => 70447.20,
        'normalized_grade' => 'all_risk',
    ]);

    return $quote->fresh();
}

it('renderiza la vista con un token válido', function (): void {
    cotizacionPublica();

    $this->get('/cotizaciones/abcdefghijklmnop')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Cotizaciones/Comparador')
            ->where('totalOpciones', 2)
            ->where('cobertura.label', 'Todo Riesgo'));
});

it('404 con un token que no existe', function (): void {
    cotizacionPublica();

    $this->get('/cotizaciones/qqqqqqqqqqqqqqqq')->assertNotFound();
});

it('404 cuando el token no tiene el formato esperado', function (): void {
    $this->get('/cotizaciones/corto')->assertNotFound();
});

it('404 cuando no hay alternativas para mostrar', function (): void {
    $quote = cotizacionPublica();
    $quote->alternatives()->forceDelete();

    $this->get('/cotizaciones/abcdefghijklmnop')->assertNotFound();
});

// El punto de la decisión de producto: el cliente que abre el link al día siguiente tiene que ver
// la página, con el CTA de contratar apagado. Un 404 le daría la espalda.
it('una cotización vencida renderiza igual, marcada como no vigente', function (): void {
    cotizacionPublica(['expires_at' => now()->subDay(), 'status' => 'expired']);

    $this->get('/cotizaciones/abcdefghijklmnop')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('vigente', false));
});

it('una cotización del día está vigente', function (): void {
    cotizacionPublica(['expires_at' => Quote::endOfBusinessDay()]);

    $this->get('/cotizaciones/abcdefghijklmnop')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('vigente', true));
});

// ── Filtración de datos personales ──────────────────────────────────────────

it('no filtra patente, DNI ni nombre del cliente', function (): void {
    cotizacionPublica();

    $response = $this->get('/cotizaciones/abcdefghijklmnop')->assertOk();

    // Sobre el HTML crudo: atrapa la fuga esté en la prop que esté, no solo en la que se me
    // ocurrió mirar.
    $response->assertDontSee('ZZZ999')
        ->assertDontSee('11111111')
        ->assertDontSee('Nombre Inconfundible')
        ->assertDontSee('7654');
});

it('no expone los tokens ni el snapshot completo', function (): void {
    cotizacionPublica();

    $this->get('/cotizaciones/abcdefghijklmnop')
        ->assertInertia(fn ($page) => $page
            ->missing('vehiculo.patente')
            ->missing('quote')
            ->missing('cliente')
            ->missing('riskSnapshot'));
});

it('sí muestra el vehículo, que es para lo que el cliente entró', function (): void {
    cotizacionPublica();

    $this->get('/cotizaciones/abcdefghijklmnop')
        ->assertInertia(fn ($page) => $page
            ->where('vehiculo.marca', 'Peugeot')
            ->where('vehiculo.modelo', '2008')
            ->where('vehiculo.year', 2017)
            ->where('vehiculo.descripcion', 'Peugeot 2008 1.6 Active'));
});

// ── noindex ─────────────────────────────────────────────────────────────────

it('sale con las dos señales de noindex', function (): void {
    cotizacionPublica();

    $this->get('/cotizaciones/abcdefghijklmnop')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('name="robots"', false);
});

it('el noindex no se filtra al resto de la app', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertHeaderMissing('X-Robots-Tag')
        ->assertDontSee('name="robots"', false);
});

// ── Recomendación ───────────────────────────────────────────────────────────

it('expone las dos recomendadas con su razón', function (): void {
    $quote = cotizacionPublica();
    [$galicia, $triunfo] = $quote->alternatives;

    $quote->update([
        'recommended_alternative_id' => $galicia->id,
        'presented_alternative_ids' => [$galicia->id, $triunfo->id],
        'presentation_reasons' => [
            (string) $galicia->id => 'La franquicia más baja.',
            (string) $triunfo->id => 'Sale menos por mes.',
        ],
    ]);

    $this->get('/cotizaciones/abcdefghijklmnop')
        ->assertInertia(fn ($page) => $page
            ->where('recomendadas.principal.planId', $galicia->id)
            ->where('recomendadas.principal.razon', 'La franquicia más baja.')
            ->has('comparacion.comunes')
            ->has('comparacion.soloB'));
});

// Sin número configurado la vista no puede armar links de WhatsApp: los CTA se esconden en vez
// de generar un wa.me roto.
it('pasa el número público de WhatsApp cuando está configurado', function (): void {
    config(['whatsapp.public_number' => '5493510000000']);
    cotizacionPublica();

    $this->get('/cotizaciones/abcdefghijklmnop')
        ->assertInertia(fn ($page) => $page->where('whatsappNumber', '5493510000000'));
});

it('pasa null cuando no hay número configurado', function (): void {
    config(['whatsapp.public_number' => null]);
    cotizacionPublica();

    $this->get('/cotizaciones/abcdefghijklmnop')
        ->assertInertia(fn ($page) => $page->where('whatsappNumber', null));
});

it('sin recomendación la vista se sirve igual', function (): void {
    cotizacionPublica();

    $this->get('/cotizaciones/abcdefghijklmnop')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('recomendadas', null)
            ->where('comparacion', null)
            ->where('totalOpciones', 2));
});

// ─── CTA "La quiero" ──────────────────────────────────────────────────────────────────────────
// El botón abre el checkout directo: si el cliente ya eligió, no hay razón para hacerlo volver
// al chat. Los guards de dominio (vigencia, pertenencia de la alternativa) los prueba
// QuoteServiceCheckoutTest; acá se prueba la traducción al canal web.

it('desde un celular abre el checkout y redirige al formulario', function (): void {
    $quote = cotizacionPublica();
    $alternative = $quote->alternatives->first();

    $this->post('/cotizaciones/abcdefghijklmnop/checkout', [
        'alternative_id' => $alternative->id,
        'movil' => true,
    ])->assertRedirect();

    $quote->refresh();

    expect($quote->status)->toBe('checkout_pending')
        ->and($quote->checkout_alternative_id)->toBe($alternative->id)
        ->and($quote->conversation->aiState()['checkout_done'])->toBeTrue();

    $this->post('/cotizaciones/abcdefghijklmnop/checkout', [
        'alternative_id' => $alternative->id,
        'movil' => true,
    ])->assertRedirect(route('checkout.show', ['token' => $quote->fresh()->checkout_token]));
});

it('desde escritorio manda el link por WhatsApp en vez de redirigir', function (): void {
    Queue::fake();
    config(['services.whatsapp.phone_number_id' => '123456']);

    $quote = cotizacionPublica();
    $alternative = $quote->alternatives->first();

    $this->from('/cotizaciones/abcdefghijklmnop')
        ->post('/cotizaciones/abcdefghijklmnop/checkout', [
            'alternative_id' => $alternative->id,
            'movil' => false,
        ])
        ->assertRedirect('/cotizaciones/abcdefghijklmnop')
        ->assertSessionHasNoErrors();

    expect($quote->fresh()->status)->toBe('checkout_pending');

    Queue::assertPushed(SendWhatsAppMessage::class, function (SendWhatsAppMessage $job) use ($quote): bool {
        // El texto del job es privado; se lee con un closure bindeado en vez de aflojar la clase.
        $texto = (fn (): string => $this->text)->call($job);

        return str_contains($texto, $quote->fresh()->checkout_token);
    });
});

it('rechaza el CTA de una cotización vencida con un mensaje para el cliente', function (): void {
    $quote = cotizacionPublica(['expires_at' => now()->subDay()]);

    $this->from('/cotizaciones/abcdefghijklmnop')
        ->post('/cotizaciones/abcdefghijklmnop/checkout', [
            'alternative_id' => $quote->alternatives->first()->id,
            'movil' => true,
        ])
        ->assertRedirect('/cotizaciones/abcdefghijklmnop')
        ->assertSessionHasErrors('alternative_id');

    expect($quote->fresh()->checkout_token)->toBeNull();
});

it('rechaza una alternativa que no es de esta cotización', function (): void {
    cotizacionPublica();
    $ajena = QuoteAlternative::factory()->create(['quote_id' => Quote::factory()->create()->id]);

    $this->from('/cotizaciones/abcdefghijklmnop')
        ->post('/cotizaciones/abcdefghijklmnop/checkout', [
            'alternative_id' => $ajena->id,
            'movil' => true,
        ])
        ->assertSessionHasErrors('alternative_id');
});

it('404 al contratar con un token que no existe', function (): void {
    cotizacionPublica();

    $this->post('/cotizaciones/nnnnnnnnnnnnnnnn/checkout', [
        'alternative_id' => 1,
        'movil' => true,
    ])->assertNotFound();
});

// El endpoint es escritura sin autenticación por cookie: no hay autoridad ambiente que CSRF
// pueda proteger, y exigirlo rompería el link abierto días después con un 419.
it('no exige token CSRF', function (): void {
    $quote = cotizacionPublica();

    $this->withMiddleware()
        ->post('/cotizaciones/abcdefghijklmnop/checkout', [
            'alternative_id' => $quote->alternatives->first()->id,
            'movil' => true,
        ])
        ->assertRedirect();
});

it('no abre el checkout si no hay a quién mandarle el link', function (): void {
    Queue::fake();
    config(['services.whatsapp.phone_number_id' => '123456']);

    // Sin BSUID y sin teléfono del cliente no hay destinatario posible: el outbound resuelve uno
    // u otro (ver Conversation::recipientPhone()).
    $quote = cotizacionPublica();
    $quote->conversation->update(['ext_user_id' => null]);
    $quote->conversation->customer->update(['phone' => null]);

    $this->from('/cotizaciones/abcdefghijklmnop')
        ->post('/cotizaciones/abcdefghijklmnop/checkout', [
            'alternative_id' => $quote->alternatives->first()->id,
            'movil' => false,
        ])
        ->assertSessionHasErrors('alternative_id');

    // Sin este guard la cotización quedaría en checkout_pending mientras al cliente le decimos
    // que no se pudo.
    $quote->refresh();

    expect($quote->status)->not->toBe('checkout_pending')
        ->and($quote->checkout_token)->toBeNull();

    Queue::assertNotPushed(SendWhatsAppMessage::class);
});
