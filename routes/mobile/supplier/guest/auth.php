<?php

use App\Http\Controllers\Api\Mobile\V1\Supplier\Auth\SupplierSessionController;
use Illuminate\Support\Facades\Route;

// Supplier sign-in (D25). Loaded outside the token group, inside `supplier.`.
Route::post('auth/login', [SupplierSessionController::class, 'store'])->name('auth.login');
