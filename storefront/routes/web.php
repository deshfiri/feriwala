<?php

use App\Http\Controllers\CartController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\SystemController;
use App\Http\Middleware\LoadStorefront;
use Illuminate\Support\Facades\Route;

// Everything a customer sees: session, CSRF and the shop's own status come first.
Route::middleware(LoadStorefront::class)->group(function (): void {
    Route::get('/', [CatalogController::class, 'home'])->name('home');
    Route::get('/products', [CatalogController::class, 'listing'])->name('products.index');
    Route::get('/category/{slug}', [CatalogController::class, 'listing'])->name('categories.show');
    Route::get('/search', [CatalogController::class, 'listing'])->name('search');
    Route::get('/product/{slug}', [CatalogController::class, 'show'])->name('products.show');

    Route::get('/cart', [CartController::class, 'show'])->name('cart.show');
    Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
    Route::post('/cart/update', [CartController::class, 'update'])->name('cart.update');
    Route::post('/cart/remove', [CartController::class, 'remove'])->name('cart.remove');

    Route::get('/checkout', [CheckoutController::class, 'form'])->name('checkout.form');
    Route::post('/checkout', [CheckoutController::class, 'submit'])->name('checkout.submit');
    Route::get('/checkout/{token}', [CheckoutController::class, 'review'])->name('checkout.review');
    Route::post('/checkout/{token}/place', [CheckoutController::class, 'place'])->name('checkout.place');

    Route::get('/order/{id}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/order/{id}/pay', [OrderController::class, 'pay'])->name('orders.pay');
    Route::post('/order/{id}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('/order/{id}/code', [OrderController::class, 'sendCode'])->name('orders.code');
    Route::post('/order/{id}/confirm', [OrderController::class, 'confirm'])->name('orders.confirm');
    Route::get('/order/{id}/return', [OrderController::class, 'returnForm'])->name('orders.return');
    Route::post('/order/{id}/return', [OrderController::class, 'returnSubmit'])->name('orders.return.submit');
    Route::get('/payment/return', [OrderController::class, 'paymentReturn'])->name('payment.return');
    Route::get('/track', [OrderController::class, 'trackForm'])->name('track.form');
    Route::post('/track', [OrderController::class, 'trackSubmit'])->name('track.submit');

    Route::get('/api/areas', [SystemController::class, 'areas'])->name('areas');
    Route::post('/locale', [SystemController::class, 'locale'])->name('locale.update');
    Route::get('/about', [SystemController::class, 'about'])->name('about');
    Route::get('/contact', [SystemController::class, 'contact'])->name('contact');
    Route::get('/robots.txt', [SystemController::class, 'robots'])->name('robots');

    // An unknown address is a 404 that still wears the shop's layout.
    Route::fallback(fn () => abort(404));
});
