<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\SmsServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    SmsServiceProvider::class,
];
