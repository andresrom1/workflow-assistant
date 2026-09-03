<?php

namespace Tests;

use App\Contracts\EmissionProvider;
use App\Contracts\Quotability;
use App\Contracts\QuotationProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\StubEmissionProvider;
use Tests\Support\StubQuotability;
use Tests\Support\StubQuotationProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ningún test sale a Internet. Lo que no esté falsificado con Http::fake()
        // revienta acá en vez de pegarle a un proveedor real con las credenciales
        // del .env de la máquina. Ver ROADMAP, bitácora 2026-09-03.
        Http::preventStrayRequests();

        // Por defecto, el gate de quotability no pega a Visred: devuelve Quotable.
        // Los tests de quotability sobreescriben este bind con el resolver real
        // (VisredQuotabilityResolver) + Http::fake + DisambiguationAgent::fake.
        $this->app->bind(Quotability::class, StubQuotability::class);

        // Cotización: por defecto un stub determinístico (sin red). Los tests del
        // path real bindean VisredQuotationProvider con Http::fake.
        $this->app->bind(QuotationProvider::class, StubQuotationProvider::class);

        // Emisión: ídem. Los tests del path real usan VisredEmissionProvider con Http::fake.
        $this->app->bind(EmissionProvider::class, StubEmissionProvider::class);
    }
}
