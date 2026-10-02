<?php

use App\Domain\Courier\Enums\CourierProviderCode;
use App\Integrations\Courier\Drivers\ManualCourierDriver;

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
    ],

];
