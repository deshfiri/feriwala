<?php

use App\Http\Controllers\Api\Mobile\V1\Supplier\Auth\SupplierSessionController;
use Illuminate\Support\Facades\Route;

Route::get('auth/me', [SupplierSessionController::class, 'show'])->name('auth.me');
Route::post('auth/logout', [SupplierSessionController::class, 'destroy'])->name('auth.logout');
