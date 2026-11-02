<?php

/*
|--------------------------------------------------------------------------
| The Feriwala ERP connection
|--------------------------------------------------------------------------
|
| Three lines connect this store, shown once by the ERP's admin dashboard
| (Websites > the partner's website > Connect the store): ERP_BASE_URL,
| STORE_ID and STORE_TOKEN. ERP_KEY_ID and ERP_SECRET are the earlier names for
| the last two and are still read, so an existing installation keeps working.
|
*/

return [

    'base_url' => rtrim((string) env('ERP_BASE_URL', ''), '/'),

    'store_id' => (string) (env('STORE_ID') ?: env('ERP_KEY_ID', '')),

    'store_token' => (string) (env('STORE_TOKEN') ?: env('ERP_SECRET', '')),

    // Only when one deployment serves several websites: the host this one is.
    'storefront_host' => env('ERP_STOREFRONT_HOST') ?: null,

    'timeout' => (int) env('ERP_TIMEOUT', 10),

    // Local development only: skips certificate verification. Ignored unless APP_ENV=local.
    'insecure_tls' => (bool) env('ERP_INSECURE_TLS', false),

    // Milliseconds to wait before the second and third attempt at a 429 or 5xx.
    'backoff_ms' => [250, 750],

];
