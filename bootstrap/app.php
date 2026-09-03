<?php

use App\Http\Middleware\EnsureAccountIsActivated;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventSearchIndexing;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTeamUrlDefaults;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            // Runs before Inertia shares props so the locale and translations
            // shipped to the client match the one the server rendered with.
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
        ]);

        // Applied per-route to authenticated ERP and admin groups. The public
        // landing site and partner storefronts must stay indexable (§34.1, §34.3),
        // so this is deliberately not a global web middleware.
        $middleware->alias([
            'noindex' => PreventSearchIndexing::class,
            'activated' => EnsureAccountIsActivated::class,
        ]);

        /*
         * Payment gateways cannot carry a CSRF token. The signature check in
         * PaymentWebhookController is what authenticates these instead, and it
         * runs before the payment is even looked up (§17.3, §26.4).
         */
        $middleware->validateCsrfTokens(except: [
            'webhooks/payment/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
