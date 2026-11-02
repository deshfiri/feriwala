<?php

use App\Http\Controllers\Api\Mobile\V1\Supplier\Offers\OfferController;
use Illuminate\Support\Facades\Route;

// Approved Products and their offers (D25, P13-13). Read-only.
Route::middleware('mobile.operational')->group(function () {
    Route::get('offers', [OfferController::class, 'index'])->name('offers.index');
    Route::get('offers/{offer}', [OfferController::class, 'show'])->name('offers.show');
});
