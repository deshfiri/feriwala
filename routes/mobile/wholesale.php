<?php

use App\Http\Controllers\Api\Mobile\V1\Wholesale\WholesaleCartController;
use App\Http\Controllers\Api\Mobile\V1\Wholesale\WholesaleCheckoutController;
use App\Http\Controllers\Api\Mobile\V1\Wholesale\WholesaleOrderController;
use App\Http\Controllers\Api\Mobile\V1\Wholesale\WholesaleOrderPaymentReturnController;
use Illuminate\Support\Facades\Route;

/*
 * Wholesale buying (§14): the cart, the checkout over it, and the account's own
 * orders. Self-scoped through the signed-in person and their account; nothing a
 * client sends is priced. Closed until the business is activated.
 */
Route::middleware('mobile.activated')->prefix('wholesale')->name('wholesale.')->group(function () {
    Route::prefix('cart')->name('cart.')->group(function () {
        Route::get('/', [WholesaleCartController::class, 'show'])->name('show');
        Route::post('items', [WholesaleCartController::class, 'store'])->name('items.store');
        Route::patch('items/{item}', [WholesaleCartController::class, 'update'])->name('items.update');
        Route::delete('items/{item}', [WholesaleCartController::class, 'destroy'])->name('items.destroy');
        Route::post('prices', [WholesaleCartController::class, 'acceptPrices'])->name('prices.accept');
    });

    Route::prefix('checkout')->name('checkout.')->group(function () {
        Route::get('/', [WholesaleCheckoutController::class, 'show'])->name('show');
        Route::put('coupon', [WholesaleCheckoutController::class, 'applyCoupon'])->name('coupon.apply');
        Route::delete('coupon', [WholesaleCheckoutController::class, 'removeCoupon'])->name('coupon.remove');
        Route::put('address', [WholesaleCheckoutController::class, 'useAddress'])->name('address.use');
        Route::put('addresses/{type}', [WholesaleCheckoutController::class, 'updateAddress'])
            ->whereIn('type', ['billing', 'shipping'])
            ->name('addresses.update');
        Route::post('confirmation', [WholesaleCheckoutController::class, 'confirm'])->name('confirmation.store');
        Route::delete('confirmation', [WholesaleCheckoutController::class, 'withdrawConfirmation'])->name('confirmation.destroy');
    });

    Route::prefix('orders')->name('orders.')->group(function () {
        Route::get('/', [WholesaleOrderController::class, 'index'])->name('index');
        Route::post('/', [WholesaleOrderController::class, 'store'])->name('store');
        Route::get('{order}', [WholesaleOrderController::class, 'show'])->name('show');
        Route::post('{order}/cancellation', [WholesaleOrderController::class, 'cancel'])->name('cancellation.store');
        Route::post('{order}/payment', [WholesaleOrderController::class, 'pay'])->name('payment.store');

        // The app tells the server the gateway sent the person back, and what it said.
        Route::post('{order}/payment/return', [WholesaleOrderPaymentReturnController::class, 'success'])->name('payment.return');
        Route::post('{order}/payment/failed', [WholesaleOrderPaymentReturnController::class, 'failed'])->name('payment.failed');
        Route::post('{order}/payment/cancelled', [WholesaleOrderPaymentReturnController::class, 'cancelled'])->name('payment.cancelled');
    });
});
