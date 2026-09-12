<?php

use App\Integrations\Payment\Gateways\SslCommerz\SslCommerzGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | All eight gateways §26 requires are listed so they are visible in one
    | place, but only those with an implemented driver, switched on, and fully
    | credentialled are offered at checkout.
    |
    | `enabled` here is the **shipped default**, not the live answer. Switching a
    | gateway on is an administrator's decision (§26.4) and an administrator
    | cannot edit a committed file, so the decision lives in settings and this is
    | only what applies until somebody makes one. A newly added provider
    | therefore arrives disabled: nobody has decided anything about it yet.
    |
    | Credentials are NEVER read from here. They live encrypted in the settings
    | table, under separate sandbox and live keys (§26.4, §36, D7).
    |
    */

    'gateways' => [

        'sslcommerz' => [
            'driver' => SslCommerzGateway::class,
            'enabled' => env('PAYMENT_SSLCOMMERZ_ENABLED', true),
            'label' => 'SSLCommerz',
        ],

        'eps' => [
            'driver' => null,
            'enabled' => false,
            'label' => 'EPS',
        ],

        'surjopay' => [
            'driver' => null,
            'enabled' => false,
            'label' => 'SurjoPay',
        ],

        'amarpay' => [
            'driver' => null,
            'enabled' => false,
            'label' => 'AmarPay',
        ],

        'bkash' => [
            'driver' => null,
            'enabled' => false,
            'label' => 'bKash',
        ],

        'nagad' => [
            'driver' => null,
            'enabled' => false,
            'label' => 'Nagad',
        ],

        // Kept disabled until merchant accounts and supported currency flows
        // exist — the driver contract is the same either way (D4).
        'stripe' => [
            'driver' => null,
            'enabled' => false,
            'label' => 'Stripe',
        ],

        'paypal' => [
            'driver' => null,
            'enabled' => false,
            'label' => 'PayPal',
        ],

    ],

];
