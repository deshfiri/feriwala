<?php

return [

    'locales' => ['en', 'bn'],

    // Behind a load balancer or proxy you control: believe X-Forwarded-For and -Proto.
    'trusted_proxy' => (bool) env('TRUSTED_PROXY', false),

    // Both are the signing secrets the ERP shows when it registers a webhook; the
    // second is the one being retired during a rotation.
    'webhook_secret' => env('WEBHOOK_SECRET') ?: null,
    'webhook_secret_previous' => env('WEBHOOK_SECRET_PREVIOUS') ?: null,
    'webhook_tolerance_seconds' => 300,

    // Seconds a read of the ERP is kept before it is asked again.
    'cache_ttl' => [
        'site' => (int) env('CACHE_TTL_SITE', 300),
        'catalog' => (int) env('CACHE_TTL_CATALOG', 60),
        'product' => (int) env('CACHE_TTL_PRODUCT', 120),
        'categories' => (int) env('CACHE_TTL_CATEGORIES', 600),
    ],

    // For hosting with no shell: GET /migrate?token=… runs the migrations.
    'migrate_token' => env('MIGRATE_TOKEN') ?: null,

];
