<?php

use App\Http\Controllers\Api\Mobile\V1\Supplier\Stock\StockController;
use Illuminate\Support\Facades\Route;

// Availability foundation (D25, P13-15): a Supplier only submits; staff decide.
Route::middleware('mobile.operational')->group(function () {
    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    Route::post('offers/{offer}/stock-updates', [StockController::class, 'store'])->name('stock.updates.store');
});
