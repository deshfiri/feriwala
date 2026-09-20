<?php

use App\Http\Controllers\Api\Storefront\V1\CategoryController;
use App\Http\Controllers\Api\Storefront\V1\ConnectionController;
use App\Http\Controllers\Api\Storefront\V1\CustomerController;
use App\Http\Controllers\Api\Storefront\V1\InventoryController;
use App\Http\Controllers\Api\Storefront\V1\OrderController;
use App\Http\Controllers\Api\Storefront\V1\ProductController;
use Illuminate\Support\Facades\Route;

/*
 * The storefront API, v1 (contract §2–§9, §17, D11).
 *
 * Served at /api/storefront/v1. The contract is frozen at v1: an endpoint here
 * may be added, a field may be added, but nothing may be removed, renamed or
 * narrowed without a v2 that has been approved first.
 *
 * Every authenticated route runs, outermost first:
 *
 *   storefront.log    — every call written to api_logs, refused ones included
 *   storefront.auth   — HTTPS, credential, timestamp, signature, nonce
 *   storefront.rate   — the credential's budget for this class of call
 *   storefront.scope  — the scope this endpoint needs
 *
 * **There is no route here that names a website.** The website is the one the
 * credential belongs to, bound by `storefront.auth`, and there is deliberately
 * no wallet, ledger, KYC, commission or account surface at all (contract §1).
 *
 * Every write carries an `Idempotency-Key`, checked by `storefront.idempotent`
 * before the route runs: the same key is answered once, with the same bytes,
 * however many times it arrives (contract §4.7).
 *
 * Return and refund requests (contract §6.3) arrive with the returns module.
 */
Route::prefix('storefront/v1')
    ->name('storefront.v1.')
    ->group(function () {
        // Liveness. Unauthenticated, and says nothing but that it is alive.
        Route::get('health', fn () => response()->json(['status' => 'ok']))
            ->middleware('storefront.log')
            ->name('health');

        Route::middleware(['storefront.log', 'storefront.auth'])->group(function () {
            Route::get('connection', ConnectionController::class)
                ->middleware('storefront.rate:other')
                ->name('connection');

            Route::middleware(['storefront.rate:read', 'storefront.scope:catalog:read'])->group(function () {
                Route::get('products', [ProductController::class, 'index'])->name('products.index');
                Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');
                Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
                Route::get('categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
            });

            Route::middleware(['storefront.rate:read', 'storefront.scope:inventory:read'])->group(function () {
                Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
                Route::get('inventory/{sku}', [InventoryController::class, 'show'])->name('inventory.show');
            });

            // Reading back this website's own orders (contract §5.3).
            Route::middleware(['storefront.rate:read', 'storefront.scope:orders:read'])->group(function () {
                Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
                Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            });

            /*
             * Submitting an order, opening its payment again, and cancelling it
             * before it is paid (contract §6.1, §6.1.2).
             */
            Route::middleware(['storefront.rate:write', 'storefront.scope:orders:write', 'storefront.idempotent'])->group(function () {
                Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
                Route::post('orders/{order}/payment-session', [OrderController::class, 'paymentSession'])
                    ->name('orders.payment-session');
                Route::post('orders/{order}/cancellation', [OrderController::class, 'cancel'])->name('orders.cancel');
            });

            // The website's own customers (contract §6.2).
            Route::middleware(['storefront.rate:write', 'storefront.scope:customers:write', 'storefront.idempotent'])->group(function () {
                Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
                Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
            });
        });
    });
