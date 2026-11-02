<?php

use App\Http\Controllers\Api\Mobile\V1\Supplier\ListingLots\ListingLotController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\ListingLots\ListingLotItemController;
use Illuminate\Support\Facades\Route;

// Listing lots: a draft-then-submit-once batch of product entries. Images for an
// entry go through the `listings/{listing}/media` routes, as on the web.
Route::middleware('mobile.operational')->group(function () {
    Route::get('listing-lots', [ListingLotController::class, 'index'])->name('listing-lots.index');
    Route::post('listing-lots', [ListingLotController::class, 'store'])->name('listing-lots.store');
    Route::get('listing-lots/{lot}', [ListingLotController::class, 'show'])->name('listing-lots.show');
    Route::post('listing-lots/{lot}/submission', [ListingLotController::class, 'submit'])->name('listing-lots.submission.store');
    Route::post('listing-lots/{lot}/archive', [ListingLotController::class, 'archive'])->name('listing-lots.archive');

    Route::post('listing-lots/{lot}/items', [ListingLotItemController::class, 'store'])->name('listing-lots.items.store');
    Route::patch('listing-lots/{lot}/items/{item}', [ListingLotItemController::class, 'update'])->name('listing-lots.items.update');
    Route::post('listing-lots/{lot}/items/{item}/archive', [ListingLotItemController::class, 'destroy'])->name('listing-lots.items.archive');
});
