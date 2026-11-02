<?php

use App\Http\Controllers\Api\Mobile\V1\Activation\CheckoutController;
use App\Http\Controllers\Api\Mobile\V1\Activation\PaymentStatusController;
use Illuminate\Support\Facades\Route;

// The combined activation payment and what happens after the gateway (§9, §26.4).
// On the §5.4 allow-list (`checkout.`), so open before activation.
Route::prefix('checkout')->name('activation.')->group(function () {
    Route::get('/', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('/', [CheckoutController::class, 'pay'])->name('checkout.pay');
    Route::post('coupon', [CheckoutController::class, 'coupon'])->name('checkout.coupon');

    Route::get('payment', [PaymentStatusController::class, 'show'])->name('payment.show');
    Route::post('payment/verify', [PaymentStatusController::class, 'verify'])->name('payment.verify');
});
