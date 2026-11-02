<?php

use App\Http\Controllers\Api\Mobile\V1\Catalog\AllocatedStockController;
use App\Http\Controllers\Api\Mobile\V1\Catalog\CatalogController;
use App\Http\Controllers\Api\Mobile\V1\Catalog\MyProductWebsiteController;
use Illuminate\Support\Facades\Route;

/*
 * The central catalogue, from a business account's side (§10, §12, §13).
 *
 * Read-only. A partner selects from the central catalogue and never writes a
 * product (§12): no route here creates, updates or deletes one. The only write
 * is putting an already-bought product on one of the partner's own storefronts.
 *
 * Every area is closed until the business is activated, as on the web.
 */
Route::middleware('mobile.activated')->group(function () {
    Route::prefix('catalog')->name('catalog.')->group(function () {
        Route::get('/', [CatalogController::class, 'index'])->name('index');
        Route::get('wholesale', [CatalogController::class, 'wholesale'])->name('wholesale.index');
        Route::get('wholesale/{product}', [CatalogController::class, 'showWholesale'])->name('wholesale.show');
        Route::get('dropshipping', [CatalogController::class, 'dropshipping'])->name('dropshipping.index');
        Route::get('dropshipping/{product}', [CatalogController::class, 'showDropshipping'])->name('dropshipping.show');
        Route::get('{product}', [CatalogController::class, 'show'])
            ->name('show')
            ->where('product', '(?!wholesale$|dropshipping$)[^/]+');
    });

    // What the account has bought, and putting a bought product on a storefront.
    Route::get('my-products', [CatalogController::class, 'mine'])->name('catalog.mine.index');
    Route::put('my-products/{product}/websites/{website}', [MyProductWebsiteController::class, 'update'])
        ->name('catalog.mine.websites.update');

    // Central stock set aside for this account (§19).
    Route::get('allocated-stock', [AllocatedStockController::class, 'index'])->name('catalog.allocated-stock.index');
});
