<?php

use App\Http\Controllers\Api\Mobile\V1\Supplier\Withdrawals\WithdrawalController;
use Illuminate\Support\Facades\Route;

// The Supplier's own withdrawal requests (D25, P13-24). Moves money: the request
// reserves the amount on the wallet in one locked transaction.
Route::middleware('mobile.operational')->group(function () {
    Route::get('withdrawals', [WithdrawalController::class, 'index'])->name('withdrawals.index');
    Route::get('withdrawals/options', [WithdrawalController::class, 'options'])->name('withdrawals.options');
    Route::post('withdrawals', [WithdrawalController::class, 'store'])->name('withdrawals.store');
    Route::get('withdrawals/{withdrawal}', [WithdrawalController::class, 'show'])->name('withdrawals.show');
});
