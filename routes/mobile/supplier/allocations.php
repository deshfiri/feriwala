<?php

use App\Http\Controllers\Api\Mobile\V1\Supplier\Allocations\AllocationController;
use Illuminate\Support\Facades\Route;

// Order lines allocated to the Supplier (D25, P13-21). Read-only.
Route::middleware('mobile.operational')->group(function () {
    Route::get('allocations', [AllocationController::class, 'index'])->name('allocations.index');
    Route::get('allocations/{item}', [AllocationController::class, 'show'])->name('allocations.show');
});
