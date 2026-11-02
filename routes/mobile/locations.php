<?php

use App\Http\Controllers\Api\Mobile\V1\Locations\LocationController;
use Illuminate\Support\Facades\Route;

// The location directory's cascade lookups. Reference data, but behind the same
// activation gate as the address forms they serve.
Route::middleware('mobile.activated')->prefix('locations')->name('locations.')->group(function () {
    Route::get('divisions', [LocationController::class, 'divisions'])->name('divisions');
    Route::get('{type}/{sourceId}/children', [LocationController::class, 'children'])->name('children');
});
