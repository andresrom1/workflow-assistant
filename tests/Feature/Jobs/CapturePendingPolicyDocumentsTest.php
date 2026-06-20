<?php

use App\Contracts\EmissionProvider;
use App\Enums\PolicyDocumentKind;
use App\Enums\PolicyDocumentSource;
use App\Jobs\CapturePendingPolicyDocuments;
use App\Models\PolicyDocument;
use App\Models\Poliza;
use App\Models\PolizaProviderRef;
use App\Services\PolicyDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\StubsPendingDocuments;

uses(RefreshDatabase::class);

/**
 * Puerto de emisión falso que devuelve blobs neutros para los `kind` pedidos, tomados
 * de un mapa `kind => contents`. Si un kind no está en el mapa, lo omite (= todavía
 * generándose). Registra los argumentos de la última llamada para aserción.
 */
function fakeEmissionProvider(array $ready): EmissionProvider
{
    return new class($ready) implements EmissionProvider
    {
        use StubsPendingDocuments;

        public ?string $token = null;

        /** @var list<string>|null */
        public ?array $askedKinds = null;

        public function __construct(private readonly array $ready) {}

        public function emit(array $request): array
        {
            return [];
        }

        public function capturePendingDocuments(string $documentToken, string $productId, array $kinds): array
        {
            $this->token = $documentToken;
            $this->askedKinds = $kinds;

            $documents = [];
            foreach ($kinds as $kind) {
                if (isset($this->ready[$kind])) {
                    $documents[] = [
                        'kind' => $kind,
                        'filename' => "{$kind}.pdf",
                        'mime' => 'application/pdf',
                        'contents' => $this->ready[$kind],
                    ];
                }
            }

            return $documents;
        }
    };
}

function polizaWithPendingRef(array $kinds, string $token = '32094'): Poliza
{
    $poliza = Poliza::factory()->create();
    PolizaProviderRef::create([
        'poliza_id' => $poliza->id,
        'document_token' => $token,
        'product_id' => 'auto',
        'pending_document_kinds' => $kinds,
    ]);

    return $poliza;
}

it('captura los documentos pendientes, los persiste y borra la referencia cuando no queda nada', function () {
    Storage::fake('r2');
    $poliza = polizaWithPendingRef(['certificado', 'circulation-card']);

    $provider = fakeEmissionProvider([
        'certificado' => 'CERTBYTES',
        'circulation-card' => 'CARDBYTES',
    ]);
    app()->instance(EmissionProvider::class, $provider);

    (new CapturePendingPolicyDocuments($poliza->id))->handle($provider, app(PolicyDocumentService::class));

    // Re-pidió con el token opaco y los kinds pendientes.
    expect($provider->token)->toBe('32094')
        ->and($provider->askedKinds)->toBe(['certificado', 'circulation-card']);

    // Ambos persistidos como documentos de emisión.
    $docs = PolicyDocument::where('poliza_id', $poliza->id)->get();
    expect($docs)->toHaveCount(2)
        ->and($docs->pluck('kind')->all())->toEqualCanonicalizing([PolicyDocumentKind::Certificado, PolicyDocumentKind::CirculationCard])
        ->and($docs->every(fn (PolicyDocument $d): bool => $d->source === PolicyDocumentSource::VisredEmission))->toBeTrue();

    // Referencia efímera borrada: ya no hay nada que reintentar.
    expect(PolizaProviderRef::where('poliza_id', $poliza->id)->exists())->toBeFalse();
});

it('persiste lo que estaba listo, descuenta de la referencia y la mantiene para reintentar lo que falta', function () {
    Storage::fake('r2');
    $poliza = polizaWithPendingRef(['certificado', 'circulation-card']);

    // Solo el certificado está listo; la tarjeta de circulación sigue generándose.
    $provider = fakeEmissionProvider(['certificado' => 'CERTBYTES']);
    app()->instance(EmissionProvider::class, $provider);

    (new CapturePendingPolicyDocuments($poliza->id))->handle($provider, app(PolicyDocumentService::class));

    expect(PolicyDocument::where('poliza_id', $poliza->id)->count())->toBe(1);

    $ref = PolizaProviderRef::where('poliza_id', $poliza->id)->firstOrFail();
    expect($ref->pending_document_kinds)->toBe(['circulation-card'])
        ->and($ref->last_attempted_at)->not->toBeNull();
});

it('es no-op y limpia la referencia si ya no quedan pendientes', function () {
    Storage::fake('r2');
    $poliza = Poliza::factory()->create();
    PolizaProviderRef::create([
        'poliza_id' => $poliza->id,
        'document_token' => '32094',
        'product_id' => 'auto',
        'pending_document_kinds' => [],
    ]);

    $provider = fakeEmissionProvider([]);
    (new CapturePendingPolicyDocuments($poliza->id))->handle($provider, app(PolicyDocumentService::class));

    expect($provider->askedKinds)->toBeNull() // nunca llamó al proveedor
        ->and(PolizaProviderRef::where('poliza_id', $poliza->id)->exists())->toBeFalse();
});
