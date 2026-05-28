<?php

// app/Providers/AppServiceProvider.php

namespace App\Providers;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Adapters\OpenAI\AgentToolAdapter;
use App\AI\InsuranceOrchestrator;
use App\Repositories\ConversationRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\VehicleRepository;
use App\Services\CustomerIdentificationService;
use App\Services\Firebase\FirebaseTokenVerifier;
use App\Services\Firebase\KreaitTokenVerifier;
use App\Services\VehicleIdentificationService;
use App\Services\WhatsApp\WhatsAppOutboundService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Registrar Repositories
        $this->app->singleton(CustomerRepository::class);
        $this->app->singleton(VehicleRepository::class);
        $this->app->singleton(ConversationRepository::class);

        // Registrar Services
        $this->app->singleton(CustomerIdentificationService::class, fn ($app) => new CustomerIdentificationService(
            $app->make(CustomerRepository::class),
            $app->make(VehicleRepository::class),
            $app->make(ConversationRepository::class),
        ));
        $this->app->singleton(CustomerIdentificationService::class);
        $this->app->singleton(VehicleIdentificationService::class);

        // Registrar Adapters
        $this->app->singleton(AgentToolAdapter::class);
        $this->app->singleton(WhatsAppAdapter::class);

        // WhatsApp
        $this->app->singleton(WhatsAppOutboundService::class);
        $this->app->singleton(InsuranceOrchestrator::class);

        // Verificación de Firebase ID Token (Admin SDK kreait). Se mockea en tests.
        $this->app->singleton(FirebaseTokenVerifier::class, KreaitTokenVerifier::class);
    }

    public function boot(): void
    {
        // Rate limiter del endpoint /api/mobile/v1/auth/link.
        // 5 intentos / 15 min por MobileAccount autenticada (o por IP si
        // todavía no hay user, ej. requests sin token Sanctum válido).
        // Frena brute-force de DNI sobre una cuenta Google comprometida.
        RateLimiter::for('mobile-link', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinutes(15, 5)->by((string) $key);
        });
    }
}
