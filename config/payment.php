<?php

use App\Integrations\Payment\Gateways\SslCommerz\SslCommerzGateway;

return [

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | All eight gateways §26 requires are listed so they are visible in one
    | place, but only those with an implemented driver and `enabled => true` are
    | offered at checkout.
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
