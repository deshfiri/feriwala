<?php

use App\Http\Controllers\Supplier\Auth\AuthenticatedSupplierSessionController;
use App\Http\Controllers\Supplier\Auth\NewSupplierPasswordController;
use App\Http\Controllers\Supplier\Auth\RegisteredSupplierController;
use App\Http\Controllers\Supplier\Auth\SupplierEmailVerificationNotificationController;
use App\Http\Controllers\Supplier\Auth\SupplierEmailVerificationPromptController;
use App\Http\Controllers\Supplier\Auth\SupplierMobileVerificationController;
use App\Http\Controllers\Supplier\Auth\SupplierPasswordResetLinkController;
use App\Http\Controllers\Supplier\Auth\VerifySupplierEmailController;
use App\Http\Controllers\Supplier\DashboardController;
use Illuminate\Support\Facades\Route;

/*
 * The Supplier account domain's own routes (D25, P13-1).
 *
 * A wholly separate route tree from `web.php`'s Client/Partner/staff routes,
 * on the `supplier` guard throughout. `guest:supplier` and `auth:supplier`
 * check only the `suppliers` provider — a Client/Partner `web` session
 * satisfies neither, and a Supplier session can never reach a `web.php`
 * route gated by `auth` (default guard), or vice versa (D25 authentication
 * boundary).
 */
Route::prefix('supplier')
    ->name('supplier.')
    ->middleware('noindex')
    ->group(function () {
        Route::middleware('guest:supplier')->group(function () {
            Route::get('register', [RegisteredSupplierController::class, 'create'])
                ->name('register');
            Route::post('register', [RegisteredSupplierController::class, 'store']);

            Route::get('login', [AuthenticatedSupplierSessionController::class, 'create'])
                ->name('login');
            Route::post('login', [AuthenticatedSupplierSessionController::class, 'store']);

            Route::get('forgot-password', [SupplierPasswordResetLinkController::class, 'create'])
                ->name('password.request');
            Route::post('forgot-password', [SupplierPasswordResetLinkController::class, 'store'])
                ->name('password.email');

            Route::get('reset-password/{token}', [NewSupplierPasswordController::class, 'create'])
                ->name('password.reset');
            Route::post('reset-password', [NewSupplierPasswordController::class, 'store'])
                ->name('password.update');
        });

        Route::middleware('auth:supplier')->group(function () {
            Route::post('logout', [AuthenticatedSupplierSessionController::class, 'destroy'])
                ->name('logout');

            Route::get('verification/notice', SupplierEmailVerificationPromptController::class)
                ->name('verification.notice');
            Route::get('verification/verify/{id}/{hash}', VerifySupplierEmailController::class)
                ->middleware(['signed', 'throttle:6,1'])
                ->name('verification.verify');
            Route::post('verification/notification', [SupplierEmailVerificationNotificationController::class, 'store'])
                ->middleware('throttle:6,1')
                ->name('verification.send');

            Route::get('verification/mobile', [SupplierMobileVerificationController::class, 'show'])
                ->name('verification.mobile');
            Route::post('verification/mobile/code', [SupplierMobileVerificationController::class, 'send'])
                ->name('verification.mobile.send');
            Route::post('verification/mobile', [SupplierMobileVerificationController::class, 'verify'])
                ->name('verification.mobile.verify');

            Route::get('dashboard', DashboardController::class)->name('dashboard');
        });
    });
