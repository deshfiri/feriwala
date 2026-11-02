<?php

use App\Http\Controllers\Api\Mobile\V1\Supplier\Wallet\WalletController;
use Illuminate\Support\Facades\Route;

// The Supplier's own wallet and statement (D25, P13-23). Read-only: balances move
// only through the actions that cause them.
Route::middleware('mobile.operational')->group(function () {
    Route::get('wallet', [WalletController::class, 'show'])->name('wallet.show');
    Route::get('wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions.index');
});
