<?php
// app/Providers/AppServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Repositories\CustomerRepository;
use App\Repositories\VehicleRepository;
use App\Repositories\ConversationRepository;
use App\Services\CustomerIdentificationService;
use App\Adapters\OpenAI\AgentToolAdapter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Registrar Repositories
        $this->app->singleton(CustomerRepository::class);
        $this->app->singleton(VehicleRepository::class);
        $this->app->singleton(ConversationRepository::class);

        // Registrar Services
        $this->app->singleton(CustomerIdentificationService::class, function ($app) {
            return new CustomerIdentificationService(
                $app->make(CustomerRepository::class),
                $app->make(VehicleRepository::class),
                $app->make(ConversationRepository::class),
            );
        });

        // Registrar Adapters
        $this->app->singleton(AgentToolAdapter::class, function ($app) {
            return new AgentToolAdapter(
                $app->make(CustomerIdentificationService::class),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
