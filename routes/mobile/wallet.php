<?php

use App\Http\Controllers\Api\Mobile\V1\Wallet\WalletController;
use Illuminate\Support\Facades\Route;

// The account's wallet (§23). Read-only; closed until the business is activated.
Route::middleware('mobile.activated')->prefix('wallet')->name('wallet.')->group(function () {
    Route::get('/', [WalletController::class, 'show'])->name('show');
    Route::get('usable', [WalletController::class, 'usable'])->name('usable');
    Route::get('transactions', [WalletController::class, 'transactions'])->name('transactions.index');
    Route::get('transactions/{transaction}', [WalletController::class, 'transaction'])->name('transactions.show');
});
