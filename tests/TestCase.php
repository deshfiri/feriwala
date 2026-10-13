<?php

namespace Tests;

use App\Integrations\Store\Contracts\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * No test resolves a real host name (P2 D3). Every name resolves to one
     * public documentation address; an address stays itself. A test about
     * where a name points binds its own resolver over this one.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return [filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : '93.184.216.34'];
            }
        });
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
