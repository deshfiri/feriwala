<?php

use App\Http\Controllers\Api\Mobile\V1\Supplier\Fulfilment\FulfilmentController;
use Illuminate\Support\Facades\Route;

// Self-service fulfilment commitments: confirm, decline, start preparing, mark ready.
Route::middleware('mobile.operational')->group(function () {
    Route::get('fulfilment', [FulfilmentController::class, 'index'])->name('fulfilment.index');
    Route::get('fulfilment/{commitment}', [FulfilmentController::class, 'show'])->name('fulfilment.show');
    Route::post('fulfilment/{commitment}/advance', [FulfilmentController::class, 'advance'])->name('fulfilment.advance');
});
