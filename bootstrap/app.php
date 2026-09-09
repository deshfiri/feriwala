<?php

use App\Http\Middleware\ApplyConfiguredSessionLifetime;
use App\Http\Middleware\EnsureAccountIsEntitled;
use App\Http\Middleware\EnsureBusinessAccountIsActivated;
use App\Http\Middleware\EnsureIdentityHasPlatformAccess;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreventSearchIndexing;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackAuthenticatedSession;
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

        /*
         * Prepended, not appended: `StartSession` reads `session.lifetime` when
         * it builds the store and again when it writes the cookie, so anything
         * setting that value has to run first (§6, §36).
         */
        $middleware->web(prepend: [
            ApplyConfiguredSessionLifetime::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            /*
             * The identity gate is global, not per-route (D23). A suspended
             * login must lose every panel, and a gate that has to be remembered
             * on each route group is one somebody will eventually forget.
             */
            EnsureIdentityHasPlatformAccess::class,
            // Runs before Inertia shares props so the locale and translations
            // shipped to the client match the one the server rendered with.
            SetLocale::class,
            /*
             * After the locale, because the "new device" alert it can raise is
             * written in the reader's language; and after the identity gate,
             * because a session that gate has just ended is not one to record
             * (§6).
             */
            TrackAuthenticatedSession::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Applied per-route to authenticated ERP and admin groups. The public
        // landing site and partner storefronts must stay indexable (§34.1, §34.3),
        // so this is deliberately not a global web middleware.
        $middleware->alias([
            'noindex' => PreventSearchIndexing::class,

            // The commercial gate, applied to business ERP routes only.
            // Administration is identity plus permission and never uses it.
            'business.activated' => EnsureBusinessAccountIsActivated::class,

            /*
             * The entitlement gate: `entitled:staff_limit` (§8.1).
             *
             * Sits *inside* `business.activated` — an account has to be trading
             * before what it bought is the question. Never on an admin route:
             * administration is permission-driven and needs no package (D23).
             */
            'entitled' => EnsureAccountIsEntitled::class,
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
