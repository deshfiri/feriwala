<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storefront domain
    |--------------------------------------------------------------------------
    |
    | Every dedicated website gets a subdomain here from the moment it is asked
    | for, so it has an address before the partner's own domain is registered
    | by hand (D9). The storefront application resolves the website from the
    | host it was reached on (P5-31).
    |
    */

    'storefront_domain' => env('STOREFRONT_DOMAIN', 'feriwala.shop'),

    /*
    |--------------------------------------------------------------------------
    | Subdomains nobody may claim
    |--------------------------------------------------------------------------
    |
    | Names that belong to the platform or would be mistaken for it. A partner
    | shop answering on `admin.` or `api.` is a phishing surface, not a
    | storefront.
    |
    */

    'reserved_subdomains' => [
        'www', 'api', 'admin', 'app', 'mail', 'smtp', 'ftp', 'cdn', 'static',
        'assets', 'status', 'support', 'help', 'billing', 'pay', 'checkout',
        'account', 'accounts', 'dashboard', 'erp', 'feriwala', 'shop', 'store',
        'webhook', 'webhooks', 'staging', 'dev', 'test',
    ],

    /*
    |--------------------------------------------------------------------------
    | Grace period
    |--------------------------------------------------------------------------
    |
    | How long a storefront keeps serving after the package that entitles it
    | lapses (§16.4, §24.3). A lapse is a late renewal far more often than it is
    | a departure, and taking a shop down the same hour punishes its customers.
    |
    */

    'grace_days' => 7,

    /*
    |--------------------------------------------------------------------------
    | Renewal reminders
    |--------------------------------------------------------------------------
    |
    | Days before a domain or hosting term ends at which the partner is told.
    | One message per stage, not one a day (§41).
    |
    */

    'renewal_reminder_days' => [30, 14, 7, 1],

    /*
    |--------------------------------------------------------------------------
    | Default term
    |--------------------------------------------------------------------------
    |
    | Months a domain registration or hosting term is bought for by default.
    |
    */

    'term_months' => 12,

    /*
    |--------------------------------------------------------------------------
    | Storefront API (contract §3, §4.6)
    |--------------------------------------------------------------------------
    |
    | `clock_skew_seconds` and `nonce_ttl_seconds` are the contract's own
    | figures and are frozen with it (§3.2): changing them changes what a
    | storefront has to do, so they are here to be read, not tuned.
    |
    | Rate limits are per credential, per minute, and are the contract's
    | defaults; a website may be given its own (§4.6).
    |
    | `rotation_grace_minutes` is how long a rotated secret keeps verifying,
    | so a storefront has time to redeploy with the new one.
    |
    */

    'api' => [
        'clock_skew_seconds' => 300,
        'nonce_ttl_seconds' => 600,
        'rotation_grace_minutes' => (int) env('STOREFRONT_ROTATION_GRACE_MINUTES', 1440),
        'default_page_size' => 50,
        'max_page_size' => 200,
        'rate_limits' => [
            'read' => 600,
            'write' => 120,
            'other' => 300,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks (contract §7.3)
    |--------------------------------------------------------------------------
    |
    | The first attempt, then a retry after each of these delays, in seconds:
    | 10s, 30s, 2m, 10m, 30m, 2h, 6h, 12h. After the last the delivery is
    | failed and waits in the failed-sync queue for a person. These are the
    | contract's figures and are frozen with it.
    |
    */

    'webhooks' => [
        'queue' => env('WEBSITE_WEBHOOK_QUEUE', 'webhooks'),
        'timeout_seconds' => 10,
        'backoff' => [10, 30, 120, 600, 1800, 7200, 21600, 43200],
    ],

];
