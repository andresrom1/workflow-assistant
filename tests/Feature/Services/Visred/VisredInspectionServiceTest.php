<?php

use App\Models\InspectionPhoto;
use App\Services\Visred\VisredInspectionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const TYPES_URL = 'https://visred.test/v1/patrimoniales/vehicles/params/inspection-types*';

const INSPECCION_URL = 'https://visred.test/v1/patrimoniales/vehicles/emitir/123/inspeccion/';

beforeEach(function () {
    config()->set('visred.base_url', 'https://visred.test');
    config()->set('visred.sandbox', false);
    Cache::flush();
    Cache::put('visred:access_token', 'TESTTOKEN', 3300);
    Storage::fake('r2');
});

/**
 * @param  list<string>  $ids
 */
function inspectionTypes(array $ids)
{
    return Http::response(array_map(fn (string $id): array => ['id' => $id, 'description' => "Foto {$id}"], $ids));
}

function inspectionPhoto(string $key, string $path): InspectionPhoto
{
    return new InspectionPhoto(['photo_key' => $key, 'storage_path' => $path]);
}

it('arma inspecciones para los tipos requeridos que tenemos foto, en base64 desde R2', function () {
    Http::fake([
        TYPES_URL => inspectionTypes(['foto-frontal', 'foto-atras', 'costado-izquierdo', 'velocimetro']),
    ]);
    Storage::disk('r2')->put('p/frente.jpg', 'FRENTE');
    Storage::disk('r2')->put('p/atras.jpg', 'ATRAS');
    Storage::disk('r2')->put('p/izq.jpg', 'IZQ');

    $photos = [
        inspectionPhoto('frente', 'p/frente.jpg'),
        inspectionPhoto('atras', 'p/atras.jpg'),
        inspectionPhoto('lateral_i', 'p/izq.jpg'),
    ];

    $inspections = app(VisredInspectionService::class)->buildInspections('sancor', 'auto', $photos);

    // velocimetro es requerido pero no tenemos foto → se omite.
    expect($inspections)->toHaveCount(3)
        ->and($inspections[0])->toBe(['type_id' => 'foto-frontal', 'image_base64' => base64_encode('FRENTE')])
        ->and($inspections[1])->toBe(['type_id' => 'foto-atras', 'image_base64' => base64_encode('ATRAS')])
        ->and($inspections[2])->toBe(['type_id' => 'costado-izquierdo', 'image_base64' => base64_encode('IZQ')]);
});

it('omite un tipo requerido cuyo foto falta en R2', function () {
    Http::fake([TYPES_URL => inspectionTypes(['foto-frontal'])]);
    // No ponemos el archivo en R2.

    $inspections = app(VisredInspectionService::class)->buildInspections('sancor', 'auto', [
        inspectionPhoto('frente', 'p/missing.jpg'),
    ]);

    expect($inspections)->toBe([]);
});

it('postea la inspección post-emisión con el shape de Visred (id/document_base64)', function () {
    Http::fake([INSPECCION_URL => Http::response(['tasks_list' => []])]);

    app(VisredInspectionService::class)->submitPostEmission(123, [
        ['type_id' => 'foto-frontal', 'image_base64' => 'AAAA'],
        ['type_id' => 'foto-atras', 'image_base64' => 'BBBB'],
    ]);

    Http::assertSent(fn (Request $r) => $r->url() === INSPECCION_URL
        && $r['inspections'][0]['id'] === 'foto-frontal'
        && $r['inspections'][0]['document_base64'] === 'AAAA'
        && $r['inspections'][1]['id'] === 'foto-atras');
});

it('lista los inspection-type ids requeridos por la compañía', function () {
    Http::fake([TYPES_URL => inspectionTypes(['foto-frontal', 'foto-atras', 'cedula'])]);

    $ids = app(VisredInspectionService::class)->requiredTypeIds('galicia', 'auto');

    expect($ids)->toBe(['foto-frontal', 'foto-atras', 'cedula']);
});
