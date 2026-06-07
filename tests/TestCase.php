<?php

namespace Tests;

use App\Contracts\Quotability;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\StubQuotability;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Por defecto, el gate de quotability no pega a Visred: devuelve Quotable.
        // Los tests de quotability sobreescriben este bind con el resolver real
        // (VisredQuotabilityResolver) + Http::fake + DisambiguationAgent::fake.
        $this->app->bind(Quotability::class, StubQuotability::class);
    }
}
