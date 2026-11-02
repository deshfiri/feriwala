<?php

use App\Http\Controllers\Api\Mobile\V1\Banks\BankController;
use Illuminate\Support\Facades\Route;

// The bank directory's cascade lookups. Reference data, but behind the same
// activation gate as the payout-method form they serve.
Route::middleware('mobile.activated')->prefix('banks')->name('banks.')->group(function () {
    Route::get('/', [BankController::class, 'index'])->name('index');
    Route::get('{bankCode}/branches', [BankController::class, 'branches'])->name('branches');
});
