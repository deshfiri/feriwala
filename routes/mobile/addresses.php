<?php

use App\Http\Controllers\Api\Mobile\V1\Addresses\AddressController;
use Illuminate\Support\Facades\Route;

// The account's own address book (§31.3). Closed until the business is activated.
Route::middleware('mobile.activated')->prefix('addresses')->name('addresses.')->group(function () {
    Route::get('/', [AddressController::class, 'index'])->name('index');
    Route::post('/', [AddressController::class, 'store'])->name('store');
    Route::put('{address}', [AddressController::class, 'update'])->name('update');
    Route::post('{address}/default', [AddressController::class, 'setDefault'])->name('default');
    Route::post('{address}/archive', [AddressController::class, 'archive'])->name('archive');
});
