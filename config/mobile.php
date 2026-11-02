<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mobile API
    |--------------------------------------------------------------------------
    |
    | The bearer-token API at /api/mobile/v1 (see docs/api/mobile.md). Token
    | lifetime lives in config/sanctum.php (`SANCTUM_TOKEN_EXPIRATION_MINUTES`).
    |
    */

    // Requests per minute, per signed-in person (per address before sign-in).
    'rate_limit_per_minute' => (int) env('MOBILE_API_RATE_LIMIT', 120),

];
