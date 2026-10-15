<?php

declare(strict_types=1);

namespace Storefront;

use Storefront\Erp\Client;

/**
 * The few shared services, in one place so a test can swap the ERP client.
 */
final class App
{
    private static ?Client $erp = null;

    public static function erp(): Client
    {
        return self::$erp ??= Client::fromEnv();
    }

    public static function useErp(?Client $client): void
    {
        self::$erp = $client;
    }

    public static function root(): string
    {
        return dirname(__DIR__);
    }
}
