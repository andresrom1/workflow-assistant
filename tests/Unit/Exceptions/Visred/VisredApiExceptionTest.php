<?php

use App\Exceptions\Visred\VisredApiException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

/**
 * Arma una respuesta de error de Visred con el `field_errors` dado.
 *
 * Sin contenedor a propósito: `fromResponse()` es pura (`Response::json()` es
 * `json_decode` + `data_get`), así que el test corre en Unit — donde `Pest.php`
 * NO bindea `TestCase` — y queda rápido y hermético.
 */
function visredFieldErrorsResponse(mixed $fieldErrors, int $status = 400): Response
{
    return new Response(new Psr7Response($status, [], (string) json_encode([
        'success' => false,
        'error' => [
            'message' => 'Error de validación.',
            'code' => 'validation_error',
            'field_errors' => $fieldErrors,
        ],
    ])));
}

it('normaliza field_errors aplanando lo anidado con notación de puntos', function (mixed $raw, array $expected) {
    expect(VisredApiException::fromResponse(visredFieldErrorsResponse($raw))->fieldErrors())
        ->toBe($expected);
})->with([
    // El contrato viejo (plano) tiene que quedar byte a byte igual.
    'plano: dos campos' => [
        ['product_id' => ['Requerido.'], 'vehicle' => ['El año es inválido.']],
        ['product_id' => ['Requerido.'], 'vehicle' => ['El año es inválido.']],
    ],
    'plano: varios mensajes en un campo' => [
        ['dni' => ['Requerido.', 'Debe tener 8 dígitos.']],
        ['dni' => ['Requerido.', 'Debe tener 8 dígitos.']],
    ],

    // El caso de producción del 2026-08-03: antes devolvía ['payment' => ['']].
    'anidado: serializer de un nivel' => [
        ['payment' => ['credit_card_brand_id' => ['Invalid pk "naranja" - object does not exist.']]],
        ['payment.credit_card_brand_id' => ['Invalid pk "naranja" - object does not exist.']],
    ],
    'anidado: tres niveles' => [
        ['a' => ['b' => ['c' => ['profundo']]]],
        ['a.b.c' => ['profundo']],
    ],
    'anidado: non_field_errors del serializer hijo' => [
        ['payment' => ['non_field_errors' => ['Falta el medio de pago.']]],
        ['payment.non_field_errors' => ['Falta el medio de pago.']],
    ],
    'anidado: varios subcampos del mismo padre' => [
        ['person_holder' => [
            'document_number' => ['Requerido.'],
            'birthdate' => ['Formato inválido.'],
        ]],
        [
            'person_holder.document_number' => ['Requerido.'],
            'person_holder.birthdate' => ['Formato inválido.'],
        ],
    ],
    'anidado: escalar como hoja' => [
        ['payment' => ['x' => 'sin lista']],
        ['payment.x' => ['sin lista']],
    ],

    // many=True: el índice del elemento sí es un segmento del path.
    'lista de dicts (many=True): conserva el índice original' => [
        ['inspections' => [[], ['image_base64' => ['Requerido.']]]],
        ['inspections.1.image_base64' => ['Requerido.']],
    ],

    // Sin texto que preservar: la clave NO se emite. Antes salía [''], que era
    // indistinguible de un error real y ocultaba el problema.
    'sin texto: null' => [['payment' => null], []],
    'sin texto: string vacío en lista' => [['payment' => ['']], []],
    'sin texto: dict vacío' => [['payment' => new stdClass], []],
    'sin texto: field_errors ausente' => [null, []],

    // Tolerancia a formas inesperadas.
    'escalar suelto en la raíz' => [['payment' => 'algo mal'], ['payment' => ['algo mal']]],
    'lista en la raíz' => [['e1', 'e2'], [['e1'], ['e2']]],
    'mensaje numérico se castea a string' => [['status' => [400]], ['status' => ['400']]],
]);

it('acumula en vez de pisar cuando dos ramas caen en el mismo path', function () {
    // `payment.a` llega por dos caminos: anidado y ya aplanado por Visred. Con
    // asignación directa uno de los dos se perdía en silencio.
    $e = VisredApiException::fromResponse(visredFieldErrorsResponse([
        'payment' => ['a' => ['desde el anidado']],
        'payment.a' => ['desde el plano'],
    ]));

    expect($e->fieldErrors())->toBe([
        'payment.a' => ['desde el anidado', 'desde el plano'],
    ]);
});

it('mantiene status y errorCode al normalizar un error anidado', function () {
    $e = VisredApiException::fromResponse(visredFieldErrorsResponse(
        ['payment' => ['credit_card_brand_id' => ['Invalid pk']]]
    ));

    expect($e->status())->toBe(400)
        ->and($e->errorCode())->toBe('validation_error')
        ->and($e->getMessage())->toBe('Error de validación.');
});
