<?php

use App\Http\Controllers\Api\Mobile\V1\Websites\WebsiteCategoryController;
use App\Http\Controllers\Api\Mobile\V1\Websites\WebsiteController;
use App\Http\Controllers\Api\Mobile\V1\Websites\WebsiteIntegrationController;
use App\Http\Controllers\Api\Mobile\V1\Websites\WebsiteOrderController;
use App\Http\Controllers\Api\Mobile\V1\Websites\WebsiteProductController;
use App\Http\Controllers\Api\Mobile\V1\Websites\WebsiteSettingsController;
use Illuminate\Support\Facades\Route;

/*
 * The account's own dedicated websites (§16). Self-scoped through the signed-in
 * person's account: no account in any URL, and a website is found among this
 * account's or not at all. Closed until the business is activated. Neither the
 * web routes nor these carry an `entitled:` gate — the allowance is answered by
 * RequestWebsite itself. Gateway return pages are web-only and not ported.
 */
Route::middleware('mobile.activated')->prefix('websites')->name('websites.')->group(function () {
    Route::get('/', [WebsiteController::class, 'index'])->name('index');
    Route::post('/', [WebsiteController::class, 'store'])->name('store');
    Route::get('create', [WebsiteController::class, 'create'])->name('create');
    Route::get('{website}', [WebsiteController::class, 'show'])->name('show');
    Route::post('{website}/charges/{charge}/payment', [WebsiteController::class, 'payCharge'])->name('charges.pay');
    Route::post('{website}/domains/{domain}/renewal', [WebsiteController::class, 'renewDomain'])->name('domains.renew');
    Route::post('{website}/hostings/{hosting}/renewal', [WebsiteController::class, 'renewHosting'])->name('hostings.renew');
    Route::put('{website}/maintenance', [WebsiteController::class, 'updateMaintenance'])->name('maintenance.update');

    Route::get('{website}/settings', [WebsiteSettingsController::class, 'show'])->name('settings.show');
    Route::put('{website}/settings', [WebsiteSettingsController::class, 'update'])->name('settings.update');
    Route::put('{website}/settings/sms-verification', [WebsiteSettingsController::class, 'updateSmsVerification'])->name('settings.sms-verification.update');
    Route::post('{website}/settings/{asset}', [WebsiteSettingsController::class, 'updateImage'])
        ->whereIn('asset', ['logo', 'banner'])
        ->name('settings.image.update');
    Route::delete('{website}/settings/{asset}', [WebsiteSettingsController::class, 'destroyImage'])
        ->whereIn('asset', ['logo', 'banner'])
        ->name('settings.image.destroy');

    Route::get('{website}/products', [WebsiteProductController::class, 'index'])->name('products.index');
    Route::post('{website}/products', [WebsiteProductController::class, 'store'])->name('products.store');
    Route::patch('{website}/products/{selection}', [WebsiteProductController::class, 'update'])->name('products.update');
    Route::put('{website}/products/{selection}/publication', [WebsiteProductController::class, 'updatePublication'])->name('products.publication.update');
    Route::delete('{website}/products/{selection}', [WebsiteProductController::class, 'destroy'])->name('products.destroy');

    Route::get('{website}/categories', [WebsiteCategoryController::class, 'index'])->name('categories.index');
    Route::post('{website}/categories', [WebsiteCategoryController::class, 'store'])->name('categories.store');
    Route::post('{website}/categories/order', [WebsiteCategoryController::class, 'reorder'])->name('categories.reorder');
    Route::patch('{website}/categories/{category}', [WebsiteCategoryController::class, 'update'])->name('categories.update');
    Route::delete('{website}/categories/{category}', [WebsiteCategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('{website}/orders', [WebsiteOrderController::class, 'index'])->name('orders.index');
    Route::get('{website}/orders/{order}', [WebsiteOrderController::class, 'show'])->name('orders.show');
    Route::post('{website}/orders/{order}/cancellation', [WebsiteOrderController::class, 'cancel'])->name('orders.cancellation.store');
    Route::post('{website}/orders/{order}/returns', [WebsiteOrderController::class, 'requestReturn'])->name('orders.returns.store');
    Route::post('{website}/orders/{order}/returns/{return}/cancellation', [WebsiteOrderController::class, 'cancelReturn'])->name('orders.returns.cancellation.store');

    Route::get('{website}/integration', [WebsiteIntegrationController::class, 'show'])->name('integration.show');
    Route::post('{website}/deliveries/{delivery}/retry', [WebsiteIntegrationController::class, 'retryDelivery'])->name('deliveries.retry');
    Route::post('{website}/sync', [WebsiteIntegrationController::class, 'syncNow'])->name('sync.store');
});
