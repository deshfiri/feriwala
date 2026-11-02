<?php

use App\Http\Controllers\Api\Mobile\V1\Supplier\Listings\ListingController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\Listings\ListingMediaController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\Listings\ListingMediaDownloadController;
use Illuminate\Support\Facades\Route;

// Product Listing Requests (D25, P13-9). Approved Suppliers only, exactly as the
// web's `supplier.operational` group: a Draft or Suspended Supplier gets
// `403 supplier_not_operational`.
Route::middleware('mobile.operational')->group(function () {
    Route::get('listings', [ListingController::class, 'index'])->name('listings.index');
    Route::get('listings/options', [ListingController::class, 'options'])->name('listings.options');
    Route::post('listings', [ListingController::class, 'store'])->name('listings.store');
    Route::get('listings/{listing}', [ListingController::class, 'show'])->name('listings.show');
    Route::patch('listings/{listing}', [ListingController::class, 'update'])->name('listings.update');
    Route::post('listings/{listing}/submission', [ListingController::class, 'submit'])->name('listings.submission.store');
    Route::post('listings/{listing}/archive', [ListingController::class, 'archive'])->name('listings.archive');

    Route::post('listings/{listing}/media', [ListingMediaController::class, 'store'])->name('listings.media.store');
    Route::post('listings/{listing}/media/reorder', [ListingMediaController::class, 'reorder'])->name('listings.media.reorder');
    Route::patch('listings/{listing}/media/{media}', [ListingMediaController::class, 'update'])->name('listings.media.update');
    Route::delete('listings/{listing}/media/{media}', [ListingMediaController::class, 'destroy'])->name('listings.media.destroy');
    Route::get('listings/media/{media}/download', [ListingMediaDownloadController::class, 'show'])->name('listings.media.download');
});
