<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthorizationServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PaymentServiceProvider;
use App\Providers\SmsServiceProvider;
use App\Providers\StoreServiceProvider;
use App\Providers\WebsiteServiceProvider;

return [
    AppServiceProvider::class,
    AuthorizationServiceProvider::class,
    FortifyServiceProvider::class,
    PaymentServiceProvider::class,
    SmsServiceProvider::class,
    StoreServiceProvider::class,
    WebsiteServiceProvider::class,
];
