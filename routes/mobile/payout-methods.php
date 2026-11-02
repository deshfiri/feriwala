<?php

use App\Http\Controllers\Api\Mobile\V1\PayoutMethods\PayoutMethodController;
use Illuminate\Support\Facades\Route;

// The account's own payout methods (D25). Closed until the business is activated.
Route::middleware('mobile.activated')->prefix('payout-methods')->name('payout-methods.')->group(function () {
    Route::get('/', [PayoutMethodController::class, 'index'])->name('index');
    Route::post('/', [PayoutMethodController::class, 'store'])->name('store');
    Route::put('{method}', [PayoutMethodController::class, 'update'])->name('update');
    Route::post('{method}/default', [PayoutMethodController::class, 'setDefault'])->name('default');
    Route::post('{method}/archive', [PayoutMethodController::class, 'archive'])->name('archive');
});
