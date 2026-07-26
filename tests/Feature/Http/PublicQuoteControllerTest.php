<?php

use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteAlternative;
use App\Models\RiskSnapshot;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
