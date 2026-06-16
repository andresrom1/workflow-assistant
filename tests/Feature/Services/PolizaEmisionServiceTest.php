<?php

use App\Contracts\EmissionProvider;
use App\Enums\InspectionPhotoStatus;
use App\Enums\PolicyDocumentKind;
use App\Enums\PolicyDocumentSource;
use App\Enums\PolizaEstado;
use App\Enums\RiskType;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\InspectionPhoto;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use App\Models\Quote;
use App\Models\Risk;
use App\Models\RiskSnapshot;
use App\Services\PolizaEmisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Crea un quote emitible (checkout submitted + alternativa elegida con su
 * quotation_result_id + sesión de checkout completa).
 *
 * @param  bool  $withRef  Si la alternativa elegida lleva su provider ref.
 * @param  bool  $requiresInspectionBefore  Si la cobertura exige inspección before-emisión.
 * @return array{0: Quote, 1: CheckoutSession, 2: RiskSnapshot}
 */
function emittableQuote(bool $withRef = true, bool $requiresInspectionBefore = false): array
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

    if ($withRef) {
        $alternative->providerRef()->create([
            'external_quote_id' => '7386',
            'company_id' => 'sancor',
            'discount_id' => '5',
            'requires_inspection_before_emission' => $requiresInspectionBefore,
        ]);
    }

    $quote->update(['checkout_alternative_id' => $alternative->id]);

    $session = CheckoutSession::create([
        'quote_id' => $quote->id,
        'quote_alternative_id' => $alternative->id,
        'status' => 'submitted',
        'nombre' => 'Juan Perez',
        'first_name' => 'Juan',
        'last_name' => 'Perez',
        'birthdate' => '1990-01-15',
        'sex_id' => 'M',
        'tax_condition_id' => 'CF',
        'dni' => '36356190',
        'email' => 'juan@example.com',
        'telefono' => '3511234567',
        'phone_prefix' => '351',
        'phone_number' => '1234567',
        'has_gnc' => true,
        'domicilio_calle' => 'San Martin',
        'domicilio_numero' => '123',
        'domicilio_cp' => '5000',
        'domicilio_provincia' => 'Cordoba',
        'domicilio_localidad' => 'Cordoba',
        'vehiculo_uso' => 'particular',
        'vehiculo_nro_chasis' => 'CHA789',
        'vehiculo_nro_motor' => 'MOT456',
        'cc_brand' => 'visa',
        'cc_pan_encrypted' => Crypt::encryptString('4111111111111111'),
        'cc_expiry_encrypted' => Crypt::encryptString('12/27'),
        'cc_holder_name_encrypted' => Crypt::encryptString('JUAN PEREZ'),
        'cc_holder_dni_encrypted' => Crypt::encryptString('36356190'),
        'photo_paths' => [],
        'submitted_at' => now(),
    ]);

    return [$quote, $session, $snapshot];
}

it('emite con el puerto, marca poliza_emitida y materializa la referencia en cartera', function () {
    [$quote, $session, $snapshot] = emittableQuote();

    // TestCase bindea StubEmissionProvider por defecto.
    $result = app(PolizaEmisionService::class)->emitir($quote, $session);

    expect($result['status'])->toBe('SUCCESS')
        ->and($result)->not->toHaveKey('presale_id'); // presale_id no sale del adapter

    expect($quote->refresh()->status)->toBe('poliza_emitida');

    // La referencia vive en cartera (Poliza), NO en Quote.metadata.
    expect($quote->metadata['emission'] ?? null)->toBeNull();

    $poliza = Poliza::where('quote_id', $quote->id)->firstOrFail();
    // La referencia durable es el número de póliza (sin presale_id).
    expect($poliza->numero)->toBe('POL-STUB-1')
        ->and($poliza->company_id)->toBe('stub-company')
        ->and($poliza->product_id)->toBe('auto');

    // Ligada a un Risk find-or-create del cliente + patente del snapshot.
    expect($poliza->risk)->not->toBeNull()
        ->and($poliza->risk->customer_id)->toBe($snapshot->customer_id)
        ->and($poliza->risk->metadata['patente'])->toBe($snapshot->vehicle->patente);
});

it('no duplica el Risk al re-materializar el mismo auto (dedup por patente)', function () {
    [$quote, $session, $snapshot] = emittableQuote();
    $patente = $snapshot->vehicle->patente;

    // Risk preexistente del mismo cliente + patente.
    $existing = Risk::create([
        'customer_id' => $snapshot->customer_id,
        'type' => RiskType::Vehicle,
        'label' => 'Auto previo',
        'metadata' => ['patente' => $patente],
    ]);

    app(PolizaEmisionService::class)->emitir($quote, $session);

    expect(Risk::where('customer_id', $snapshot->customer_id)->count())->toBe(1);
    expect(Poliza::where('quote_id', $quote->id)->firstOrFail()->risk_id)->toBe($existing->id);
});

it('arma el request neutro desde checkout + snapshot + la ref elegida', function () {
    [$quote, $session, $snapshot] = emittableQuote();
    $expectedPlate = $snapshot->vehicle->patente;

    $spy = new class implements EmissionProvider
    {
        /** @var array<string, mixed>|null */
        public ?array $captured = null;

        public function emit(array $request): array
        {
            $this->captured = $request;

            return [
                'task_id' => 't', 'status' => 'SUCCESS', 'presale_id' => 1,
                'proposal_number' => 'PR', 'policy_number' => 'P',
                'emission_status' => 'emitida', 'requires_inspection_after_emission' => false,
                'company_id' => 'sancor', 'documents' => [], 'raw' => [],
            ];
        }
    };
    app()->instance(EmissionProvider::class, $spy);

    app(PolizaEmisionService::class)->emitir($quote, $session);

    $captured = $spy->captured;
    expect($captured['quotation_result_ref'])->toBe('7386')
        ->and($captured['discount_id'])->toBe('5')
        ->and($captured['holder']['document_number'])->toBe('36356190')
        ->and($captured['holder']['first_name'])->toBe('Juan')
        ->and($captured['holder']['last_name'])->toBe('Perez')
        ->and($captured['holder']['birthdate'])->toBe('1990-01-15')
        ->and($captured['holder']['sex_id'])->toBe('M')
        ->and($captured['holder']['tax_condition_id'])->toBe('CF')
        ->and($captured['holder']['email'])->toBe('juan@example.com')
        ->and($captured['holder']['phone_prefix'])->toBe('351')
        ->and($captured['holder']['phone_number'])->toBe('1234567')
        ->and($captured['address']['zip_code'])->toBe('5000')
        ->and($captured['address']['street_name'])->toBe('San Martin')
        ->and($captured['vehicle']['plate'])->toBe($expectedPlate)
        ->and($captured['vehicle']['motor'])->toBe('MOT456')
        ->and($captured['vehicle']['chassis'])->toBe('CHA789')
        ->and($captured['vehicle']['has_gnc'])->toBeTrue()
        ->and($captured['payment']['method'])->toBe('tarjeta')
        ->and($captured['payment']['card']['number'])->toBe('4111111111111111')
        ->and($captured['payment']['card']['expire_month'])->toBe(12)
        ->and($captured['payment']['card']['expire_year'])->toBe(2027);
});

it('lanza si la alternativa elegida no tiene quotation_result_id', function () {
    [$quote, $session] = emittableQuote(withRef: false);

    expect(fn () => app(PolizaEmisionService::class)->emitir($quote, $session))
        ->toThrow(RuntimeException::class);
});

it('persiste en cartera los documentos capturados al emitir (visibles al cliente)', function () {
    Storage::fake('r2');
    [$quote, $session] = emittableQuote();

    // La inspección post-emisión es ahora interna del adapter (ver
    // VisredEmissionProviderTest); el dominio solo persiste los documentos que el
    // adapter capturó (con el presale vivo, sin que salga de él).
    app()->instance(EmissionProvider::class, new class implements EmissionProvider
    {
        public function emit(array $request): array
        {
            return [
                'task_id' => 't', 'status' => 'SUCCESS',
                'proposal_number' => 'PR', 'policy_number' => 'P', 'emission_status' => 'emitida',
                'requires_inspection_after_emission' => false, 'company_id' => 'sancor',
                'documents' => [
                    ['kind' => 'poliza', 'filename' => 'poliza.pdf', 'mime' => 'application/pdf', 'contents' => 'PDFBYTES'],
                ],
                'raw' => [],
            ];
        }
    });

    app(PolizaEmisionService::class)->emitir($quote, $session);

    $poliza = Poliza::where('quote_id', $quote->id)->firstOrFail();
    $doc = PolicyDocument::where('poliza_id', $poliza->id)->firstOrFail();

    expect($doc->kind)->toBe(PolicyDocumentKind::Poliza)
        ->and($doc->source)->toBe(PolicyDocumentSource::VisredEmission)
        ->and($doc->visible_to_client)->toBeTrue()
        ->and(Storage::disk('r2')->get($doc->storage_path))->toBe('PDFBYTES');
});

it('la emisión no falla ni persiste documentos cuando la captura viene vacía', function () {
    Storage::fake('r2');
    [$quote, $session] = emittableQuote();

    app()->instance(EmissionProvider::class, new class implements EmissionProvider
    {
        public function emit(array $request): array
        {
            return [
                'task_id' => 't', 'status' => 'SUCCESS',
                'proposal_number' => 'PR', 'policy_number' => 'P', 'emission_status' => 'emitida',
                'requires_inspection_after_emission' => false, 'company_id' => 'sancor',
                'documents' => [],
                'raw' => [],
            ];
        }
    });

    $result = app(PolizaEmisionService::class)->emitir($quote, $session);

    expect($result['status'])->toBe('SUCCESS')
        ->and($quote->refresh()->status)->toBe('poliza_emitida')
        ->and(PolicyDocument::count())->toBe(0);
});

it('pasa el bloque neutro de inspección before-emisión al puerto cuando la cobertura la exige', function () {
    [$quote, $session] = emittableQuote(requiresInspectionBefore: true);

    foreach (['frente', 'atras'] as $key) {
        InspectionPhoto::create([
            'quote_id' => $quote->id,
            'photo_key' => $key,
            'storage_path' => "p/{$key}.jpg",
            'storage_url' => 'http://r2/'.$key,
            'status' => InspectionPhotoStatus::Confirmed,
        ]);
    }

    $spy = new class implements EmissionProvider
    {
        /** @var array<string, mixed>|null */
        public ?array $captured = null;

        public function emit(array $request): array
        {
            $this->captured = $request;

            return [
                'task_id' => 't', 'status' => 'SUCCESS', 'presale_id' => 1,
                'proposal_number' => 'PR', 'policy_number' => 'P', 'emission_status' => 'emitida',
                'requires_inspection_after_emission' => false, 'company_id' => 'sancor', 'documents' => [], 'raw' => [],
            ];
        }
    };
    app()->instance(EmissionProvider::class, $spy);

    app(PolizaEmisionService::class)->emitir($quote, $session);

    // El dominio NO arma las inspecciones (eso es del adapter): pasa los ingredientes.
    $captured = $spy->captured;
    expect($captured['inspection_photos']['company_id'])->toBe('sancor')
        ->and($captured['inspection_photos']['product_id'])->toBe('auto')
        ->and(count(iterator_to_array($captured['inspection_photos']['photos'])))->toBe(2);
});

it('no pasa el bloque de inspección cuando la cobertura no la exige', function () {
    [$quote, $session] = emittableQuote(); // requires_inspection_before_emission = false

    $spy = new class implements EmissionProvider
    {
        /** @var array<string, mixed>|null */
        public ?array $captured = null;

        public function emit(array $request): array
        {
            $this->captured = $request;

            return [
                'task_id' => 't', 'status' => 'SUCCESS', 'presale_id' => 1,
                'proposal_number' => 'PR', 'policy_number' => 'P', 'emission_status' => 'emitida',
                'requires_inspection_after_emission' => false, 'company_id' => 'sancor', 'documents' => [], 'raw' => [],
            ];
        }
    };
    app()->instance(EmissionProvider::class, $spy);

    app(PolizaEmisionService::class)->emitir($quote, $session);

    expect($spy->captured)->not->toHaveKey('inspection_photos');
});

it('es idempotente: no re-emite si el quote ya está poliza_emitida (D4.2)', function () {
    [$quote, $session, $snapshot] = emittableQuote();
    $quote->update(['status' => 'poliza_emitida']);

    // Referencia ya materializada en cartera (lo que devuelve el guard).
    $risk = Risk::create([
        'customer_id' => $snapshot->customer_id,
        'type' => RiskType::Vehicle,
        'label' => 'Auto',
        'metadata' => ['patente' => $snapshot->vehicle->patente],
    ]);
    Poliza::create([
        'risk_id' => $risk->id,
        'quote_id' => $quote->id,
        'estado' => PolizaEstado::Vigente,
        'numero' => 'POL-OLD',
        'company_id' => 'sancor',
        'product_id' => 'auto',
        'metadata' => ['proposal_number' => 'PR-OLD', 'emission_status' => 'emitida', 'requires_inspection_after_emission' => false],
    ]);

    $spy = new class implements EmissionProvider
    {
        public int $emitCalls = 0;

        public function emit(array $request): array
        {
            $this->emitCalls++;

            return [
                'task_id' => 't', 'status' => 'SUCCESS', 'presale_id' => 1, 'proposal_number' => 'PR',
                'policy_number' => 'P', 'emission_status' => 'emitida',
                'requires_inspection_after_emission' => false, 'company_id' => 'sancor', 'documents' => [], 'raw' => [],
            ];
        }
    };
    app()->instance(EmissionProvider::class, $spy);

    $result = app(PolizaEmisionService::class)->emitir($quote, $session);

    expect($spy->emitCalls)->toBe(0)
        ->and($result['status'])->toBe('SUCCESS')
        ->and($result)->not->toHaveKey('presale_id')
        ->and($result['policy_number'])->toBe('POL-OLD');
});

it('lanza (para reintento del job) si la emisión no es exitosa', function () {
    [$quote, $session] = emittableQuote();

    app()->instance(EmissionProvider::class, new class implements EmissionProvider
    {
        public function emit(array $request): array
        {
            return [
                'task_id' => 't', 'status' => 'FAILURE', 'presale_id' => null,
                'proposal_number' => null, 'policy_number' => null,
                'emission_status' => null, 'requires_inspection_after_emission' => false,
                'company_id' => null, 'documents' => [], 'raw' => [],
            ];
        }
    });

    expect(fn () => app(PolizaEmisionService::class)->emitir($quote, $session))
        ->toThrow(RuntimeException::class);

    expect($quote->refresh()->status)->toBe('checkout_submitted'); // no marcó emitida
});
