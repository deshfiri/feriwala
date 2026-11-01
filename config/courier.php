<?php

use App\Domain\Courier\Enums\CourierProviderCode;
use App\Integrations\Courier\Drivers\ManualCourierDriver;
use App\Integrations\Courier\Drivers\SteadfastCourierDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Courier drivers
    |--------------------------------------------------------------------------
    |
    | Every CourierProviderCode this application knows about, and the driver
    | class behind it. Only `manual` has one in this batch (D8) -- Steadfast
    | and Pathao are seeded as disabled, credential-less `courier_providers`
    | rows (see the create_courier_providers migration) with no entry here,
    | so CourierManager::isImplemented() answers false for them honestly
    | rather than pointing at a class that does not exist.
    |
    */

    'drivers' => [
        CourierProviderCode::Manual->value => ManualCourierDriver::class,
        CourierProviderCode::Steadfast->value => SteadfastCourierDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Steadfast
    |--------------------------------------------------------------------------
    |
    | Credentials are never configured here: they live encrypted in settings
    | (`courier.steadfast.*`). This block holds only what is not secret.
    |
    | `default_base_url` is the host Steadfast's own maintained package documents
    | in its README. The package's config file ships a different default
    | (portal.packzy.com); that host is deliberately not allowed until
    | Steadfast's merchant panel names it for this account.
    |
    | In production the base address must be https and its host must be listed in
    | `allowed_hosts` -- the API key travels in every request, so an address an
    | administrator can type must not be able to point it somewhere else.
    |
    */

    'steadfast' => [
        'default_base_url' => 'https://portal.steadfast.com.bd/api/v1',
        'allowed_hosts' => ['portal.steadfast.com.bd'],
        'connect_timeout' => 5,
        'timeout' => 20,
    ],

];
