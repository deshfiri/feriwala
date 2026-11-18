<?php

use App\Http\Controllers\Api\Mobile\V1\Invoices\InvoiceController;
use Illuminate\Support\Facades\Route;

/*
 * The business's own invoices (§8.2). Outside `mobile.activated`, as on the web
 * (`subscription.invoices.*` is on the §5.4 allow-list): an account still
 * paying for activation, or one whose term has lapsed, can read its bills.
 */
Route::prefix('invoices')->name('invoices.')->group(function () {
    Route::get('/', [InvoiceController::class, 'index'])->name('index');
    Route::get('{invoice}', [InvoiceController::class, 'show'])->name('show');
});
