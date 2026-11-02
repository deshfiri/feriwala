<?php

use App\Http\Controllers\Api\Mobile\V1\WalletTopups\WalletTopUpController;
use Illuminate\Support\Facades\Route;

// Putting money into the wallet and exporting its statement (§24, §26.3, §33.7).
// Closed until the business is activated, like the wallet itself.
Route::middleware('mobile.activated')->prefix('wallet')->name('wallet-topups.')->group(function () {
    Route::get('top-up', [WalletTopUpController::class, 'options'])->name('top-up.options');
    Route::post('top-up', [WalletTopUpController::class, 'store'])->name('top-up.store');
    Route::get('statement.csv', [WalletTopUpController::class, 'export'])->name('statement.export');
});
