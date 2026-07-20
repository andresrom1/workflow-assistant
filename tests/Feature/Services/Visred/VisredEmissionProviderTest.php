<?php

use App\Exceptions\Visred\VisredApiException;
use App\Models\InspectionPhoto;
use App\Services\Visred\VisredEmissionProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

const EMITIR_URL = 'https://visred.test/v1/patrimoniales/vehicles/emitir/';

beforeEach(function () {
    config()->set('visred.base_url', 'https://visred.test');
    config()->set('visred.sandbox', false);
    config()->set('visred.poll_budget', 12);
    config()->set('visred.poll_interval', 2);
    Cache::flush();
    Cache::put('visred:access_token', 'TESTTOKEN', 3300);
    Sleep::fake();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function neutralRequest(array $overrides = []): array
{
    return array_replace_recursive([
        'quotation_result_ref' => '7386',
        'holder' => [
            'document_number' => '36356190',
            'first_name' => 'Juan',
            'last_name' => 'Pérez',
            'email' => 'juan@example.com',
            'tax_condition_id' => 'CF',
            'birthdate' => '1990-01-15',
            'sex_id' => 'M',
            'phone_prefix' => '351',
            'phone_number' => '1234567',
        ],
        'address' => [
            'zip_code' => 5000,
            'street_name' => 'San Martín',
            'street_number' => '123',
        ],
        'vehicle' => [
            'plate' => 'ABC123',
            'motor' => 'MOT456',
            'chassis' => 'CHA789',
        ],
        'payment' => [
            'method' => 'tarjeta',
            'card' => [
                'brand' => 'visa',
                'holder' => 'JUAN PEREZ',
                'number' => '4111111111111111',
                'expire_month' => 12,
                'expire_year' => 2027,
            ],
        ],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $result
 */
function taskPresaleSuccess(array $result)
{
    return Http::response(['status' => 'SUCCESS', 'ready' => true, 'message' => null, 'result' => $result]);
}

it('emite, hace polling y parsea el APIBasePreSaleResultDTO', function () {
    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't-emit', 'company_id' => 'sancor', 'product_id' => 'auto']),
        'https://visred.test/v1/tasks/t-emit/' => taskPresaleSuccess([
            'presale_id' => 55123,
            'proposal_number' => 'PROP-9',
            'policy_number' => 'POL-9',
            'status' => 'emitida',
            'require_inspection_after_emission' => false,
            'tasks_list' => [],
        ]),
    ]);

    $result = app(VisredEmissionProvider::class)->emit(neutralRequest());

    expect($result['status'])->toBe('SUCCESS')
        ->and($result)->not->toHaveKey('presale_id') // dato de Visred: no sale del adapter
        ->and($result['proposal_number'])->toBe('PROP-9')
        ->and($result['policy_number'])->toBe('POL-9')
        ->and($result['emission_status'])->toBe('emitida')
        ->and($result['requires_inspection_after_emission'])->toBeFalse()
        ->and($result['task_id'])->toBe('t-emit');
});

it('declara document_type_id=cuit cuando el documento tiene 11 dígitos (CUIT o CUIL)', function () {
    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't1']),
        'https://visred.test/v1/tasks/t1/' => taskPresaleSuccess(['presale_id' => 1]),
    ]);

    // `Customer.dni` puede contener legítimamente un CUIT/CUIL (persona jurídica, o
    // alguien que dio el CUIL en el chat/checkout) y ese valor viaja tal cual. Visred
    // rechaza la emisión con `person_holder: [""]` si un número de 11 dígitos se
    // declara como `dni` — y también si se declara como `cuil`. Verificado live
    // (2026-07-19) con el mismo quotation_result_id variando sólo este campo.
    app(VisredEmissionProvider::class)->emit(neutralRequest([
        'holder' => ['document_number' => '20301237277'],
    ]));

    Http::assertSent(fn (Request $r) => $r->url() === EMITIR_URL
        && $r['person_holder']['document_number'] === '20301237277'
        && $r['person_holder']['document_type_id'] === 'cuit');
});

it('respeta un document_type_id explícito del dominio en vez de deducirlo', function () {
    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't1']),
        'https://visred.test/v1/tasks/t1/' => taskPresaleSuccess(['presale_id' => 1]),
    ]);

    app(VisredEmissionProvider::class)->emit(neutralRequest([
        'holder' => ['document_number' => '20301237277', 'document_type_id' => 'pasaporte'],
    ]));

    Http::assertSent(fn (Request $r) => $r->url() === EMITIR_URL
        && $r['person_holder']['document_type_id'] === 'pasaporte');
});

it('mapea el request neutro a PreSaleVehicleRequest (defaults física/dni, pago aplanado)', function () {
    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't1']),
        'https://visred.test/v1/tasks/t1/' => taskPresaleSuccess(['presale_id' => 1]),
    ]);

    app(VisredEmissionProvider::class)->emit(neutralRequest());

    Http::assertSent(fn (Request $r) => $r->url() === EMITIR_URL
        && $r['quotation_result_id'] === 7386
        && $r['person_holder']['document_number'] === '36356190'
        && $r['person_holder']['person_type_id'] === 'fisica'
        && $r['person_holder']['document_type_id'] === 'dni'
        && $r['person_holder']['phone_prefix'] === '351'
        && $r['person_holder']['phone_number'] === '1234567'
        && $r['person_holder']['birthdate'] === '1990-01-15'
        && $r['person_holder']['sex_id'] === 'M'
        && $r['person_holder']['tax_condition_id'] === 'CF'
        && $r['address']['zip_code'] === 5000
        && $r['vehicle']['plate'] === 'ABC123'
        && $r['payment']['payment_method_id'] === 'tarjeta'
        && $r['payment']['credit_card_number'] === '4111111111111111'
        && $r['payment']['credit_card_expire_month'] === 12
        && $r['payment']['credit_card_expire_year'] === 2027);
});

it('emite con el CP de guarda del riesgo, no el del domicilio del tomador', function () {
    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't-zip']),
        'https://visred.test/v1/tasks/t-zip/' => taskPresaleSuccess(['presale_id' => 1]),
    ]);

    app(VisredEmissionProvider::class)->emit(neutralRequest([
        'address' => [
            'zip_code' => 5000,       // domicilio del tomador (no tarifa)
            'risk_zip_code' => 1414,  // CP de guarda del vehículo (el que tarifó)
        ],
    ]));

    Http::assertSent(fn (Request $r) => $r->url() === EMITIR_URL
        && $r['address']['zip_code'] === 1414);
});

it('propaga require_inspection_after_emission cuando la compañía lo exige', function () {
    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't-insp-after']),
        'https://visred.test/v1/tasks/t-insp-after/' => taskPresaleSuccess([
            'presale_id' => 42,
            'require_inspection_after_emission' => true,
        ]),
    ]);

    $result = app(VisredEmissionProvider::class)->emit(neutralRequest());

    expect($result['status'])->toBe('SUCCESS')
        ->and($result['requires_inspection_after_emission'])->toBeTrue();
});

it('mapea inspecciones neutras (type_id/image_base64) al shape Visred (id/document_base64)', function () {
    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't-insp']),
        'https://visred.test/v1/tasks/t-insp/' => taskPresaleSuccess(['presale_id' => 7]),
    ]);

    app(VisredEmissionProvider::class)->emit(neutralRequest([
        'inspections' => [
            ['type_id' => 'foto-frontal', 'image_base64' => 'AAAA'],
            ['type_id' => 'foto-atras', 'image_base64' => 'BBBB'],
        ],
    ]));

    Http::assertSent(fn (Request $r) => $r->url() === EMITIR_URL
        && $r['inspections'][0]['id'] === 'foto-frontal'
        && $r['inspections'][0]['document_base64'] === 'AAAA'
        && $r['inspections'][1]['id'] === 'foto-atras'
        && $r['inspections'][1]['document_base64'] === 'BBBB');
});

it('before-emisión: arma las inspecciones desde las fotos (R2→base64) y las embebe en el emit', function () {
    Storage::fake('r2');
    Storage::disk('r2')->put('p/frente.jpg', 'BYTESFRENTE');
    config()->set('visred.inspection_photo_map', ['frente' => 'foto-frontal']);

    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't-be']),
        'https://visred.test/v1/tasks/t-be/' => taskPresaleSuccess(['presale_id' => 9]),
        'https://visred.test/v1/patrimoniales/vehicles/params/inspection-types/*' => Http::response([
            ['id' => 'foto-frontal'],
        ]),
    ]);

    // Foto de dominio sin persistir: buildInspections solo lee photo_key/storage_path.
    $photo = new InspectionPhoto(['photo_key' => 'frente', 'storage_path' => 'p/frente.jpg']);

    app(VisredEmissionProvider::class)->emit(neutralRequest([
        'inspection_photos' => [
            'company_id' => 'sancor',
            'product_id' => 'auto',
            'requires_before' => true,
            'photos' => [$photo],
        ],
    ]));

    Http::assertSent(fn (Request $r) => $r->url() === EMITIR_URL
        && isset($r['inspections'])
        && $r['inspections'][0]['id'] === 'foto-frontal'
        && $r['inspections'][0]['document_base64'] === base64_encode('BYTESFRENTE'));
});

it('captura los documentos oficiales al emitir y los devuelve como blobs neutros (presale no sale)', function () {
    config()->set('visred.document_task_types', ['download-poliza' => 'poliza']);

    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't-doc', 'company_id' => 'sancor']),
        'https://visred.test/v1/tasks/t-doc/' => taskPresaleSuccess(['presale_id' => 77, 'policy_number' => 'POL-77']),
        'https://visred.test/v1/documents/' => Http::response(['result' => ['document_url' => 'https://files.visred.test/poliza-77.pdf']]),
        'https://files.visred.test/poliza-77.pdf' => Http::response('PDFBYTES'),
    ]);

    $result = app(VisredEmissionProvider::class)->emit(neutralRequest());

    expect($result)->not->toHaveKey('presale_id')
        ->and($result['documents'])->toHaveCount(1)
        ->and($result['documents'][0]['kind'])->toBe('poliza')
        ->and($result['documents'][0]['mime'])->toBe('application/pdf')
        ->and($result['documents'][0]['contents'])->toBe('PDFBYTES');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://visred.test/v1/documents/'
        && $r['presale_id'] === 77
        && $r['task_type_id'] === 'download-poliza');
});

it('deja en pending los documentos que la compañía todavía genera (un intento, sin result.url)', function () {
    config()->set('visred.document_task_types', ['download-poliza' => 'poliza']);

    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't-doc', 'company_id' => 'triunfo']),
        'https://visred.test/v1/tasks/t-doc/' => taskPresaleSuccess(['presale_id' => 77, 'policy_number' => 'POL-77']),
        // El documento todavía no está listo: result sin url. La captura hace UN intento
        // (no poll-ea inline); el reintento por minuto lo maneja el job.
        'https://visred.test/v1/documents/' => Http::response(['result' => null]),
    ]);

    $result = app(VisredEmissionProvider::class)->emit(neutralRequest());

    expect($result['status'])->toBe('SUCCESS')
        ->and($result['documents'])->toBe([])
        ->and($result['pending_documents']['token'])->toBe('77') // token opaco = presale
        ->and($result['pending_documents']['product_id'])->toBe('auto')
        ->and($result['pending_documents']['kinds'])->toBe(['poliza']);

    // Un solo POST a documentos (sin loop de polling inline).
    Http::assertSentCount(3); // emitir + task + documents
});

it('separa los documentos listos de los pendientes en una misma emisión', function () {
    config()->set('visred.document_task_types', [
        'download-poliza' => 'poliza',
        'download-certificate' => 'certificado',
    ]);

    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't-mix', 'company_id' => 'triunfo']),
        'https://visred.test/v1/tasks/t-mix/' => taskPresaleSuccess(['presale_id' => 77, 'policy_number' => 'POL-77']),
        'https://visred.test/v1/documents/' => function (Request $request) {
            return $request['task_type_id'] === 'download-poliza'
                ? Http::response(['result' => ['document_url' => 'https://files.visred.test/poliza.pdf']])
                : Http::response(['result' => null]); // certificado todavía generándose
        },
        'https://files.visred.test/poliza.pdf' => Http::response('PDFBYTES'),
    ]);

    $result = app(VisredEmissionProvider::class)->emit(neutralRequest());

    expect($result['documents'])->toHaveCount(1)
        ->and($result['documents'][0]['kind'])->toBe('poliza')
        ->and($result['pending_documents']['kinds'])->toBe(['certificado']);
});

it('capturePendingDocuments re-pide solo los kinds pedidos y devuelve los que ya están listos (presale no se expone)', function () {
    config()->set('visred.document_task_types', [
        'download-poliza' => 'poliza',
        'download-certificate' => 'certificado',
    ]);

    Http::fake([
        'https://visred.test/v1/documents/' => function (Request $request) {
            expect($request['presale_id'])->toBe(77); // decodifica el token opaco
            expect($request['task_type_id'])->toBe('download-certificate'); // solo el pedido

            return Http::response(['result' => ['document_url' => 'https://files.visred.test/cert.pdf']]);
        },
        'https://files.visred.test/cert.pdf' => Http::response('CERTBYTES'),
    ]);

    $documents = app(VisredEmissionProvider::class)->capturePendingDocuments('77', 'auto', ['certificado']);

    expect($documents)->toHaveCount(1)
        ->and($documents[0]['kind'])->toBe('certificado')
        ->and($documents[0]['contents'])->toBe('CERTBYTES');
});

it('capturePendingDocuments es no-op con token inválido o sin kinds', function () {
    Http::fake();

    expect(app(VisredEmissionProvider::class)->capturePendingDocuments('', 'auto', ['poliza']))->toBe([])
        ->and(app(VisredEmissionProvider::class)->capturePendingDocuments('77', 'auto', []))->toBe([]);

    Http::assertNothingSent();
});

it('sube la inspección post-emisión internamente con el presale cuando la compañía la exige', function () {
    Storage::fake('r2');
    Storage::disk('r2')->put('p/frente.jpg', 'BYTESFRENTE');
    config()->set('visred.inspection_photo_map', ['frente' => 'foto-frontal']);

    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't-after', 'company_id' => 'sancor']),
        'https://visred.test/v1/tasks/t-after/' => taskPresaleSuccess([
            'presale_id' => 88,
            'require_inspection_after_emission' => true,
        ]),
        'https://visred.test/v1/patrimoniales/vehicles/params/inspection-types/*' => Http::response([['id' => 'foto-frontal']]),
        'https://visred.test/v1/patrimoniales/vehicles/emitir/88/inspeccion/' => Http::response(['status' => 'OK']),
    ]);

    $photo = new InspectionPhoto(['photo_key' => 'frente', 'storage_path' => 'p/frente.jpg']);

    app(VisredEmissionProvider::class)->emit(neutralRequest([
        'inspection_photos' => [
            'company_id' => 'sancor',
            'product_id' => 'auto',
            'requires_before' => false,
            'photos' => [$photo],
        ],
    ]));

    Http::assertSent(fn (Request $r) => $r->url() === 'https://visred.test/v1/patrimoniales/vehicles/emitir/88/inspeccion/'
        && $r['inspections'][0]['id'] === 'foto-frontal'
        && $r['inspections'][0]['document_base64'] === base64_encode('BYTESFRENTE'));
});

it('respeta el budget: si la task nunca termina, corta y devuelve FAILURE', function () {
    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't-slow']),
        'https://visred.test/v1/tasks/t-slow/' => Http::response(['status' => 'PENDING', 'ready' => false]),
    ]);

    $result = app(VisredEmissionProvider::class)->emit(neutralRequest());

    expect($result['status'])->toBe('FAILURE');

    // budget=12, interval=2 → no más de 6 sleeps; no loop infinito.
    Sleep::assertSleptTimes(6);
});

it('devuelve FAILURE si la task de emisión termina en FAILURE', function () {
    Http::fake([
        EMITIR_URL => Http::response(['task_id' => 't-fail']),
        'https://visred.test/v1/tasks/t-fail/' => Http::response(['status' => 'FAILURE', 'ready' => true]),
    ]);

    $result = app(VisredEmissionProvider::class)->emit(neutralRequest());

    expect($result['status'])->toBe('FAILURE');
});

it('propaga VisredApiException ante un 400 de emitir', function () {
    Http::fake([
        EMITIR_URL => Http::response([
            'success' => false,
            'error' => [
                'message' => 'Error de validación.',
                'code' => 'validation_error',
                'field_errors' => ['person_holder' => ['birthdate requerido']],
            ],
        ], 400),
    ]);

    expect(fn () => app(VisredEmissionProvider::class)->emit(neutralRequest()))
        ->toThrow(VisredApiException::class);
});
