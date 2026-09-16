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

];
