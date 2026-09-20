<?php

use App\Integrations\Payment\Gateways\AmarPay\AmarPayGateway;
use App\Integrations\Payment\Gateways\Bkash\BkashGateway;
use App\Integrations\Payment\Gateways\Eps\EpsGateway;
use App\Integrations\Payment\Gateways\Nagad\NagadGateway;
use App\Integrations\Payment\Gateways\PayPal\PayPalGateway;
use App\Integrations\Payment\Gateways\SslCommerz\SslCommerzGateway;
use App\Integrations\Payment\Gateways\Stripe\StripeGateway;
use App\Integrations\Payment\Gateways\SurjoPay\SurjoPayGateway;

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

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Where notification settlement runs. A webhook endpoint has to answer
    | quickly — providers time out and then retry, turning one notification into
    | several — so verification and settlement happen here instead (§26.4).
    |
    | Its own queue rather than the default: a backlog of anything else must not
    | delay confirming that somebody's money arrived.
    |
    */

    'queue' => env('PAYMENT_QUEUE', 'payments'),

    /*
    |--------------------------------------------------------------------------
    | Notification rate limit
    |--------------------------------------------------------------------------
    |
    | The webhook endpoint is unauthenticated by necessity. Signed providers are
    | protected by their signature, but the ones that do not sign — which is
    | documented behaviour for some — would otherwise let anybody drive outbound
    | verification calls by posting references at us.
    |
    | Per gateway and per address, generous enough that a provider retrying a
    | genuine backlog is never touched.
    |
    */

    'webhook_rate_limit' => (int) env('PAYMENT_WEBHOOK_RATE_LIMIT', 120),

    'gateways' => [

        'sslcommerz' => [
            'driver' => SslCommerzGateway::class,
            'enabled' => env('PAYMENT_SSLCOMMERZ_ENABLED', true),
            'label' => 'SSLCommerz',

            /*
             * A stub standing in for the sandbox on a machine with no route to
             * SSLCommerz — a local address only, ignored in production and
             * never used in live mode. Unset everywhere but a developer's own
             * environment.
             */
            'sandbox_host' => env('PAYMENT_SSLCOMMERZ_SANDBOX_HOST'),
        ],

        /*
         * Configuration foundation only — the driver declares no capabilities
         * and refuses every operation. EPS issues its integration guide to
         * merchants on request rather than publishing it, and a payment
         * protocol is not something to infer. See EpsGateway.
         */
        'eps' => [
            'driver' => EpsGateway::class,
            'enabled' => false,
            'label' => 'EPS',
        ],

        'surjopay' => [
            'driver' => SurjoPayGateway::class,
            'enabled' => false,
            'label' => 'shurjoPay',
        ],

        'amarpay' => [
            'driver' => AmarPayGateway::class,
            'enabled' => false,
            'label' => 'aamarPay',
        ],

        'bkash' => [
            'driver' => BkashGateway::class,
            'enabled' => false,
            'label' => 'bKash',
        ],

        /*
         * Configuration foundation only, like EPS. Nagad's Merchant API guide
         * is issued to registered merchants, and an RSA signing scheme is not
         * something to reconstruct from the outside. See NagadGateway.
         */
        'nagad' => [
            'driver' => NagadGateway::class,
            'enabled' => false,
            'label' => 'Nagad',
        ],

        /*
         * Implemented against each provider's published API and **shipped
         * disabled** until merchant accounts exist (D4, P2-38). Neither can be
         * switched on regardless: enabling requires complete credentials, and
         * there are no accounts to issue them.
         *
         * Neither accepts BDT here. The base ledger is in taka, version 1
         * performs no exchange-rate accounting, and a gateway that cannot take
         * the currency of an amount is never offered for it.
         */
        'stripe' => [
            'driver' => StripeGateway::class,
            'enabled' => false,
            'label' => 'Stripe',
        ],

        'paypal' => [
            'driver' => PayPalGateway::class,
            'enabled' => false,
            'label' => 'PayPal',
        ],

    ],

];
