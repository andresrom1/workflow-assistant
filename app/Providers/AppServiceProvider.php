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
use App\Services\VehicleIdentificationService;
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

        // WhatsApp
        $this->app->singleton(WhatsAppOutboundService::class);
        $this->app->singleton(InsuranceOrchestrator::class);
    }

    public function boot(): void
    {
        //
    }
}
