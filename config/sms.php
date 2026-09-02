<?php

use App\Integrations\Sms\Providers\LogSmsProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | The `log` driver writes messages to the sms log channel instead of
    | sending them, so onboarding can be walked end to end before any merchant
    | account exists. Real providers arrive in Phase 8 (§30.1).
    |
    */

    'default' => env('SMS_PROVIDER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Global Switch
    |--------------------------------------------------------------------------
    |
    | §30 requires SMS to be disableable globally, by provider, and by event.
    | This is the global one; the finer controls live in settings so they can be
    | changed without a deploy.
    |
    */

    'enabled' => env('SMS_ENABLED', true),

    'providers' => [

        'log' => [
            'driver' => LogSmsProvider::class,
        ],

        // Credentials are never read from here directly — they live encrypted
        // in the settings table (§26.4, §36). These entries only declare which
        // providers exist and whether they are switched on.
        'bulksmsbd' => [
            'driver' => null,
            'enabled' => false,
        ],

        'novasms' => [
            'driver' => null,
            'enabled' => false,
        ],

        'sslcommerz' => [
            'driver' => null,
            'enabled' => false,
        ],

        'twilio' => [
            'driver' => null,
            'enabled' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | §30.2: delivery runs through queues so it never slows down the ERP.
    |
    */

    'queue' => env('SMS_QUEUE', 'sms'),

];
