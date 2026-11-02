<?php

use App\Http\Controllers\Api\Mobile\V1\Packages\PackageChangeController;
use App\Http\Controllers\Api\Mobile\V1\Packages\PackageController;
use App\Http\Controllers\Api\Mobile\V1\Packages\RenewalController;
use App\Http\Controllers\Api\Mobile\V1\Packages\SubscriptionController;
use Illuminate\Support\Facades\Route;

// Choosing a package and living with the subscription (§8). Every one of these
// is on the §5.4 allow-list (`packages.`, `subscription.`), so none sits behind
// `mobile.activated`: renewal in particular has to work after a term has lapsed.
Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
Route::post('packages/{package}/select', [PackageController::class, 'select'])->name('packages.select');

Route::prefix('subscription')->name('packages.subscription.')->group(function () {
    Route::get('/', [SubscriptionController::class, 'show'])->name('show');
    Route::post('cancel', [SubscriptionController::class, 'cancel'])->name('cancel');

    Route::get('change', [PackageChangeController::class, 'index'])->name('change.index');
    Route::post('change', [PackageChangeController::class, 'store'])->name('change.store');

    Route::get('renew', [RenewalController::class, 'show'])->name('renew.show');
    Route::post('renew', [RenewalController::class, 'pay'])->name('renew.pay');
});
