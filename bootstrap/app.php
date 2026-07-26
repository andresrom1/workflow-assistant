<?php

use App\Exceptions\Api\ApiException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\NoIndex;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Rutas mobile (app Flutter) bajo /api/mobile/v1/
            Route::middleware('api')
                ->prefix('api/mobile/v1')
                ->group(base_path('routes/mobile.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'admin' => IsAdmin::class,
            'noindex' => NoIndex::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Renderer estándar para mobile: { message, code, errors? }.
        // Solo aplica a /api/mobile/v1/* para no romper otros endpoints.
        $isMobile = fn (Request $r): bool => $r->is('api/mobile/v1/*');

        $exceptions->render(function (ApiException $e, Request $request) use ($isMobile) {
            if (! $isMobile($request)) {
                return null;
            }

            return response()->json(array_filter([
                'message' => $e->getMessage(),
                'code' => $e->errorCode(),
                'errors' => $e->errors(),
            ], fn ($v) => $v !== null), $e->httpStatus());
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isMobile) {
            if (! $isMobile($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Revisá los datos que ingresaste.',
                'code' => 'VALIDATION_FAILED',
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isMobile) {
            if (! $isMobile($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Iniciá sesión para continuar.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        });

        // Fallback para errores HTTP genéricos (404, 405, 429, 500) en rutas
        // mobile: mantener forma estándar aunque no haya code semántico.
        $exceptions->render(function (Throwable $e, Request $request) use ($isMobile) {
            if (! $isMobile($request)) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $code = match ($status) {
                404 => 'NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                429 => 'TOO_MANY_REQUESTS',
                default => 'INTERNAL_ERROR',
            };
            $message = match ($status) {
                404 => 'No encontramos lo que buscabas.',
                405 => 'Esa operación no está disponible.',
                429 => 'Esperá un momento antes de reintentar.',
                default => 'Tuvimos un problema. Probá de nuevo en un rato.',
            };

            return response()->json(['message' => $message, 'code' => $code], $status);
        });
    })->create();
