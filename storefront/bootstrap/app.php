<?php

use App\Erp\ErpException;
use App\Http\Middleware\LoadStorefront;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Basket;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            // No session, no CSRF, no shop lookup: the ERP's, the monitor's and the installer's.
            Route::group([], base_path('routes/system.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        // Plain cookies, as the plain-PHP storefront set them: a basket and a language
        // chosen before the upgrade keep working. Neither holds anything but a random
        // token or a language code.
        $middleware->encryptCookies(except: [Basket::COOKIE, LoadStorefront::LOCALE_COOKIE]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(ErpException::class);

        // An ERP that cannot answer is the shop's "try again shortly", never a stack
        // trace. Logged for the operator, never shown: an upstream message or request
        // id is not for a customer.
        $exceptions->render(function (ErpException $exception, Request $request) {
            Log::warning(sprintf('[storefront] ERP %s (%d) request_id=%s: %s', $exception->errorCode, $exception->status, $exception->requestId ?? '-', $exception->getMessage()));

            $notFound = $exception->status === 404;

            return response()->view($notFound ? 'errors.404' : 'errors.unavailable', [], $notFound ? 404 : 503);
        });

        // A stale form: say so and send the visitor back to the page they were on.
        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            flash_message('error', t('error.csrf'));

            return redirect(own_page_or($request));
        });
    })->create();
