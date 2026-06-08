<?php

// app/Providers/AppServiceProvider.php

namespace App\Providers;

use App\Adapters\AIProviders\WhatsAppAdapter;
use App\Adapters\OpenAI\AgentToolAdapter;
use App\AI\InsuranceOrchestrator;
use App\Contracts\Quotability;
use App\Contracts\QuotationProvider;
use App\Contracts\WhatsAppDispatcher;
use App\Repositories\ConversationRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\VehicleRepository;
use App\Services\CustomerIdentificationService;
use App\Services\Firebase\FirebaseTokenVerifier;
use App\Services\Firebase\KreaitTokenVerifier;
use App\Services\VehicleIdentificationService;
use App\Services\Visred\VisredQuotabilityResolver;
use App\Services\Visred\VisredQuotationProvider;
use App\Services\WhatsApp\CloudApiWhatsAppDispatcher;
use App\Services\WhatsApp\LogWhatsAppDispatcher;
use App\Services\WhatsApp\WhatsAppOutboundService;
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

        // Cotización: puerto agnóstico → VisredQuotationProvider (real siempre,
        // mismo criterio que Quotability). El mock (QuotingEngine) se eliminó en
        // Fase 4: Visred es EL proveedor, bind directo (sin selector por config).
        // En tests, TestCase bindea StubQuotationProvider para no pegar a la red.
        $this->app->singleton(QuotationProvider::class, VisredQuotationProvider::class);

        // Quotability: ¿algún proveedor cotiza este auto? Corre en identify-vehicle
        // (resolución de catálogo + desambiguación). Independiente del seam de
        // cotización: se resuelve contra Visred aunque el motor siga en mock.
        $this->app->singleton(Quotability::class, VisredQuotabilityResolver::class);

        // WhatsApp
        $this->app->singleton(WhatsAppOutboundService::class);

        // Dispatch de avisos por WhatsApp (templates). Seam por config: "cloud"
        // envía de verdad (vía Job), "log" es no-op para local/testing.
        $this->app->singleton(WhatsAppDispatcher::class, fn ($app) => config('whatsapp.dispatch_driver') === 'cloud'
            ? $app->make(CloudApiWhatsAppDispatcher::class)
            : $app->make(LogWhatsAppDispatcher::class));

        $this->app->singleton(InsuranceOrchestrator::class);

        // Verificación de Firebase ID Token (Admin SDK kreait). Se mockea en tests.
        $this->app->singleton(FirebaseTokenVerifier::class, KreaitTokenVerifier::class);
    }

    public function boot(): void
    {
        //
    }
}
