<?php

use App\Http\Controllers\Api\Mobile\V1\Auth\LoginController;
use App\Http\Controllers\Api\Mobile\V1\Auth\SessionController;
use App\Http\Controllers\Api\Mobile\V1\Auth\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

/*
 * The mobile API, v1 — served at /api/mobile/v1 (see routes/api.php).
 *
 * One file per module under routes/mobile/, each loaded below inside the
 * signed-in group. A module file declares its own sub-groups:
 *
 *   mobile.activated   closed until the business is activated (§5.4). The web
 *                      app's allow-list of pre-activation areas is inverted
 *                      into structure: those areas are registered OUTSIDE this
 *                      group, everything else INSIDE it.
 *
 * Route names are `mobile.v1.<module>.<action>`. Public identifiers are ULIDs
 * (`public_id`) or slugs, never database ids (non-negotiable 8).
 */

// Liveness. Says nothing but that it is alive.
Route::get('health', fn () => response()->json(['status' => 'ok']))->name('health');

// Signing in. No token yet, so nothing here can be self-scoped; the failure
// throttle and the second-factor limiter are what protect it (§6).
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', LoginController::class)->name('login');
    Route::post('two-factor-challenge', TwoFactorChallengeController::class)
        ->middleware('throttle:mobile-two-factor')
        ->name('two-factor-challenge');
});

// Module routes that need no token (registration, password reset). One file per
// module under routes/mobile/guest/, each throttled by its own module.
foreach (glob(__DIR__.'/mobile/guest/*.php') ?: [] as $guestRoutes) {
    require $guestRoutes;
}

/*
 * Suppliers (D25) are a separate account domain with their own tokens. Their
 * routes live under /supplier, one file per module in routes/mobile/supplier/
 * (token) and routes/mobile/supplier/guest/ (no token). Route names are
 * `mobile.v1.supplier.<module>.<action>`.
 */
Route::prefix('supplier')->name('supplier.')->group(function () {
    foreach (glob(__DIR__.'/mobile/supplier/guest/*.php') ?: [] as $guestRoutes) {
        require $guestRoutes;
    }

    Route::middleware(['auth:sanctum', 'mobile.access:supplier'])->group(function () {
        foreach (glob(__DIR__.'/mobile/supplier/*.php') ?: [] as $supplierRoutes) {
            require $supplierRoutes;
        }
    });
});

/*
 * Administration (§36), one file per module in routes/mobile/admin/. The same
 * three gates as the web panel: a second factor for the sensitive roles, the
 * default reason on staff screens, and — per route, where the web says
 * `RequirePassword` — `mobile.password`, which wants `current_password` in the
 * body. Names must match the web route (`admin.roles.store`) so config/reasons.php
 * and the policies recognise them: inside these files write `roles.store`.
 */
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'mobile.access:user', 'mobile.two-factor', 'mobile.reason'])
    ->group(function () {
        foreach (glob(__DIR__.'/mobile/admin/*.php') ?: [] as $adminRoutes) {
            require $adminRoutes;
        }
    });

Route::middleware(['auth:sanctum', 'mobile.access:user'])->group(function () {
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::get('me', [SessionController::class, 'me'])->name('me');
        Route::post('logout', [SessionController::class, 'logout'])->name('logout');
        Route::post('logout-all', [SessionController::class, 'logoutAll'])->name('logout-all');
        Route::get('devices', [SessionController::class, 'devices'])->name('devices');
        Route::delete('devices/{reference}', [SessionController::class, 'revokeDevice'])->name('devices.revoke');
    });

    // Module route files, alphabetical so the load order is stable.
    foreach (glob(__DIR__.'/mobile/*.php') ?: [] as $moduleRoutes) {
        require $moduleRoutes;
    }
});
