<?php

use App\Exceptions\Api\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Verifica que el handler de excepciones en bootstrap/app.php aplica el
 * formato estándar { message, code, errors? } a todas las respuestas de
 * error de las rutas /api/mobile/v1/*.
 *
 * Las rutas se inyectan en tiempo de test para no acoplar a controllers
 * reales — el contrato a probar es el del renderer, no el de un endpoint
 * específico.
 */
beforeEach(function (): void {
    Route::middleware('api')
        ->prefix('api/mobile/v1')
        ->group(function (): void {
            Route::get('/test/api-exception', function (): never {
                throw new ApiException(
                    'No pudimos encontrarte con esos datos. Escribile a tu PAS.',
                    'ACCOUNT_LINK_FAILED',
                    422,
                    ['dni' => ['El DNI no coincide con nuestros registros.']],
                );
            });

            Route::post('/test/validation', function (): never {
                request()->validate(['dni' => ['required', 'string']]);
                throw new RuntimeException('unreachable');
            });

            Route::middleware('auth:mobile')->get('/test/protected', fn () => ['ok' => true]);
        });
});

it('renderiza ApiException con message/code/errors y status custom', function (): void {
    $response = $this->getJson('/api/mobile/v1/test/api-exception');

    $response->assertStatus(422);
    $response->assertExactJson([
        'message' => 'No pudimos encontrarte con esos datos. Escribile a tu PAS.',
        'code' => 'ACCOUNT_LINK_FAILED',
        'errors' => ['dni' => ['El DNI no coincide con nuestros registros.']],
    ]);
});

it('renderiza ValidationException con code VALIDATION_FAILED y errors por campo', function (): void {
    $response = $this->postJson('/api/mobile/v1/test/validation', []);

    $response->assertStatus(422);
    $response->assertJson([
        'message' => 'Revisá los datos que ingresaste.',
        'code' => 'VALIDATION_FAILED',
    ]);
    expect($response->json('errors.dni'))->toBeArray();
});

it('renderiza AuthenticationException con code UNAUTHENTICATED y 401', function (): void {
    $response = $this->getJson('/api/mobile/v1/test/protected');

    $response->assertStatus(401);
    $response->assertExactJson([
        'message' => 'Iniciá sesión para continuar.',
        'code' => 'UNAUTHENTICATED',
    ]);
});

it('renderiza 404 con code NOT_FOUND para rutas inexistentes en /api/mobile/v1/*', function (): void {
    $response = $this->getJson('/api/mobile/v1/no-existe');

    $response->assertStatus(404);
    $response->assertExactJson([
        'message' => 'No encontramos lo que buscabas.',
        'code' => 'NOT_FOUND',
    ]);
});

it('omite el campo errors cuando ApiException no tiene errores por campo', function (): void {
    Route::middleware('api')
        ->prefix('api/mobile/v1')
        ->get('/test/no-errors', function (): never {
            throw new ApiException('Algo salió mal.', 'GENERIC', 500);
        });

    $response = $this->getJson('/api/mobile/v1/test/no-errors');

    $response->assertStatus(500);
    $response->assertExactJson([
        'message' => 'Algo salió mal.',
        'code' => 'GENERIC',
    ]);
});

it('no toca rutas fuera de /api/mobile/v1/*', function (): void {
    // Una validación en una ruta web debería seguir devolviendo el formato
    // default de Laravel (no nuestro renderer mobile).
    Route::middleware('api')->post('/api/web/test', function (): never {
        request()->validate(['x' => ['required']]);
        throw new RuntimeException('unreachable');
    });

    $response = $this->postJson('/api/web/test', []);

    $response->assertStatus(422);
    // El formato default de Laravel también incluye 'message' y 'errors',
    // pero NO debe incluir nuestro 'code': 'VALIDATION_FAILED'.
    expect($response->json('code'))->toBeNull();
});
