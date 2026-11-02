<?php

use App\Http\Controllers\Api\Mobile\V1\Withdrawals\WithdrawalController;
use Illuminate\Support\Facades\Route;

// The account's own withdrawal requests (§27). Closed until the business is activated.
Route::middleware('mobile.activated')->prefix('withdrawals')->name('withdrawals.')->group(function () {
    Route::get('/', [WithdrawalController::class, 'index'])->name('index');
    Route::get('options', [WithdrawalController::class, 'options'])->name('options');
    Route::post('/', [WithdrawalController::class, 'store'])->name('store');
    Route::get('{withdrawal}', [WithdrawalController::class, 'show'])->name('show');
});
