<?php

namespace App\Integrations\Store;

use App\Domain\ExternalStore\Enums\StorePlatform;
use App\Integrations\Store\Contracts\StorePlatformDriver;
use App\Integrations\Store\Drivers\DfcommerceDriver;
use Illuminate\Contracts\Container\Container;

/**
 * Picks the driver for a store's platform (D29).
 *
 * The same shape as the courier, payment and SMS managers: one contract, one
 * driver per platform, chosen by the platform the connection was made on.
 */
class StorePlatformManager
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function driver(StorePlatform $platform): StorePlatformDriver
    {
        return match ($platform) {
            StorePlatform::Dfcommerce => $this->container->make(DfcommerceDriver::class),
        };
    }
}
