<?php

use App\Http\Controllers\BankLookupController;
use App\Http\Controllers\LocationLookupController;
use App\Http\Controllers\Supplier\AccountController;
use App\Http\Controllers\Supplier\AddressController;
use App\Http\Controllers\Supplier\AllocationController;
use App\Http\Controllers\Supplier\Auth\AuthenticatedSupplierSessionController;
use App\Http\Controllers\Supplier\Auth\NewSupplierPasswordController;
use App\Http\Controllers\Supplier\Auth\RegisteredSupplierController;
use App\Http\Controllers\Supplier\Auth\SupplierEmailVerificationNotificationController;
use App\Http\Controllers\Supplier\Auth\SupplierEmailVerificationPromptController;
use App\Http\Controllers\Supplier\Auth\SupplierMobileVerificationController;
use App\Http\Controllers\Supplier\Auth\SupplierPasswordResetLinkController;
use App\Http\Controllers\Supplier\Auth\VerifySupplierEmailController;
use App\Http\Controllers\Supplier\DashboardController;
use App\Http\Controllers\Supplier\KycController;
use App\Http\Controllers\Supplier\ListingController;
use App\Http\Controllers\Supplier\OfferController;
use App\Http\Controllers\Supplier\PayableController;
use App\Http\Controllers\Supplier\PayoutMethodController;
use App\Http\Controllers\Supplier\StockController;
use App\Http\Controllers\Supplier\WalletController;
use App\Http\Controllers\Supplier\WithdrawalController;
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
            Route::post('register', [RegisteredSupplierController::class, 'store'])
                ->name('register.store');

            Route::get('login', [AuthenticatedSupplierSessionController::class, 'create'])
                ->name('login');
            Route::post('login', [AuthenticatedSupplierSessionController::class, 'store'])
                ->name('login.store');

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

            // The Supplier's own notifications, profile and password.
            Route::get('notifications', [AccountController::class, 'notifications'])->name('notifications.index');
            Route::post('notifications/read', [AccountController::class, 'markNotificationsRead'])->name('notifications.read');
            Route::get('profile', [AccountController::class, 'profile'])->name('profile.edit');
            Route::patch('profile', [AccountController::class, 'updateProfile'])->name('profile.update');
            Route::get('security', [AccountController::class, 'security'])->name('security.edit');
            Route::put('security/password', [AccountController::class, 'updatePassword'])->name('security.password.update');

            /*
             * KYC (D25, P13-7). Reachable regardless of operational status —
             * a Draft or CorrectionRequired Supplier is exactly who needs
             * this screen, and `supplier.operational` would refuse them.
             */
            Route::get('kyc', [KycController::class, 'create'])->name('kyc.create');
            Route::post('kyc/documents', [KycController::class, 'storeDocument'])->name('kyc.documents.store');
            Route::post('kyc/submit', [KycController::class, 'submit'])->name('kyc.submit');

            /*
             * Product Listing Requests (D25, P13-9). Only Approved Suppliers
             * may reach these — `supplier.operational` is the server-side
             * guard, not the navigation.
             */
            Route::middleware('supplier.operational')->group(function () {
                Route::get('listings', [ListingController::class, 'index'])->name('listings.index');
                Route::get('listings/create', [ListingController::class, 'create'])->name('listings.create');
                Route::post('listings', [ListingController::class, 'store'])->name('listings.store');
                Route::get('listings/{listing}', [ListingController::class, 'show'])->name('listings.show');
                Route::get('listings/{listing}/edit', [ListingController::class, 'edit'])->name('listings.edit');
                Route::patch('listings/{listing}', [ListingController::class, 'update'])->name('listings.update');
                Route::post('listings/{listing}/submission', [ListingController::class, 'submit'])->name('listings.submission.store');
                Route::post('listings/{listing}/archive', [ListingController::class, 'archive'])->name('listings.archive');

                // Approved Products and their offers (D25, P13-13).
                Route::get('offers', [OfferController::class, 'index'])->name('offers.index');
                Route::get('offers/{offer}', [OfferController::class, 'show'])->name('offers.show');

                // Availability foundation (D25, P13-15).
                Route::get('stock', [StockController::class, 'index'])->name('stock.index');
                Route::post('offers/{offer}/stock-updates', [StockController::class, 'store'])->name('stock.updates.store');

                // Order allocation and Supplier payables (D25, P13-21, P13-22).
                Route::get('allocations', [AllocationController::class, 'index'])->name('allocations.index');
                Route::get('allocations/{item}', [AllocationController::class, 'show'])->name('allocations.show');
                Route::get('payables', [PayableController::class, 'index'])->name('payables.index');
                Route::get('payables/{payable}', [PayableController::class, 'show'])->name('payables.show');

                // Wallet, payout methods and withdrawals (D25, P13-23, P13-24).
                Route::get('wallet', [WalletController::class, 'show'])->name('wallet.show');
                Route::get('wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions');

                Route::get('payout-methods', [PayoutMethodController::class, 'index'])->name('payout-methods.index');
                Route::post('payout-methods', [PayoutMethodController::class, 'store'])->name('payout-methods.store');
                Route::put('payout-methods/{method}', [PayoutMethodController::class, 'update'])->name('payout-methods.update');
                Route::post('payout-methods/{method}/default', [PayoutMethodController::class, 'setDefault'])->name('payout-methods.default');
                Route::post('payout-methods/{method}/archive', [PayoutMethodController::class, 'archive'])->name('payout-methods.archive');

                // The bank directory's cached Bank -> Branch lookup, behind
                // the bank payout-method form (Shared Payout Methods batch).
                Route::get('banks', [BankLookupController::class, 'banks'])->name('banks.index');
                Route::get('banks/{bankCode}/branches', [BankLookupController::class, 'branches'])->name('banks.branches');

                // Registered, pickup and return addresses (Location Directory
                // + Shared Address module).
                Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
                Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
                Route::put('addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
                Route::post('addresses/{address}/default', [AddressController::class, 'setDefault'])->name('addresses.default');
                Route::post('addresses/{address}/archive', [AddressController::class, 'archive'])->name('addresses.archive');

                // The Bangladesh location directory's cached child lookups —
                // reference data, so no owner scoping applies.
                Route::get('locations/divisions', [LocationLookupController::class, 'divisions'])->name('locations.divisions');
                Route::get('locations/{type}/{sourceId}/children', [LocationLookupController::class, 'children'])->name('locations.children');

                Route::get('withdrawals', [WithdrawalController::class, 'index'])->name('withdrawals.index');
                Route::get('withdrawals/create', [WithdrawalController::class, 'create'])->name('withdrawals.create');
                Route::post('withdrawals', [WithdrawalController::class, 'store'])->name('withdrawals.store');
                Route::get('withdrawals/{withdrawal}', [WithdrawalController::class, 'show'])->name('withdrawals.show');
            });
        });
    });
