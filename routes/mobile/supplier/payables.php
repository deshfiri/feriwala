<?php

use App\Http\Controllers\Api\Mobile\V1\Supplier\Payables\PayableController;
use Illuminate\Support\Facades\Route;

// What Feriwala owes the Supplier, per allocated order line (D25, P13-22). Read-only.
Route::middleware('mobile.operational')->group(function () {
    Route::get('payables', [PayableController::class, 'index'])->name('payables.index');
    Route::get('payables/{payable}', [PayableController::class, 'show'])->name('payables.show');
});
