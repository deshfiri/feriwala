<?php

use App\Http\Controllers\Api\Mobile\V1\Orders\ExternalStoreController;
use App\Http\Controllers\Api\Mobile\V1\Orders\OrderController;
use App\Http\Controllers\Api\Mobile\V1\Orders\StoreOrderController;
use Illuminate\Support\Facades\Route;

/*
 * Orders from every source in one list (D29), a partner's Store Orders (their own
 * storefronts), and their previously connected external stores (read-only, D30).
 * All of it closed until the business is activated.
 */
Route::middleware('mobile.activated')->prefix('orders')->name('orders.')->group(function () {
    Route::get('/', [OrderController::class, 'index'])->name('index');
    Route::get('export', [OrderController::class, 'export'])->name('export');
    Route::get('external/{order}', [OrderController::class, 'showExternal'])->name('external.show');

    Route::prefix('store-orders')->name('store-orders.')->group(function () {
        Route::get('/', [StoreOrderController::class, 'index'])->name('index');
        Route::get('{order}', [StoreOrderController::class, 'show'])->name('show');
        Route::post('{order}/cancellation', [StoreOrderController::class, 'cancel'])->name('cancel');
    });

    Route::prefix('stores')->name('stores.')->group(function () {
        Route::get('/', [ExternalStoreController::class, 'index'])->name('index');
        Route::get('{store}', [ExternalStoreController::class, 'show'])->name('show');
    });
});
