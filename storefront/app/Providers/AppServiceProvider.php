<?php

namespace App\Providers;

use App\Erp\Client;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, fn (): Client => Client::fromConfig());
    }

    public function boot(): void
    {
        // Behind a load balancer or proxy you control: believe X-Forwarded-For and -Proto.
        if (config('storefront.trusted_proxy')) {
            TrustProxies::at('*');
        }

        // The messages queued by flash_message(), shown once by the layout.
        View::composer('layouts.app', function (ViewInstance $view): void {
            $view->with('flash', (array) session()->pull('storefront_messages', []));
        });
    }
}
