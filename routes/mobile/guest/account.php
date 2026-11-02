<?php

use App\Http\Controllers\Api\Mobile\V1\Account\PasswordResetController;
use App\Http\Controllers\Api\Mobile\V1\Account\RegisterController;
use Illuminate\Support\Facades\Route;

// Registration and forgotten passwords: no token yet, so each is throttled by
// address. Fortify's own routes for these are session-based and web-only.
Route::prefix('register')->name('register.')->group(function () {
    Route::get('options', [RegisterController::class, 'options'])->middleware('throttle:30,1,mobile-register-options')->name('options');
    Route::post('/', [RegisterController::class, 'store'])->middleware('throttle:10,1,mobile-register')->name('store');
});

Route::prefix('password')->name('password.')->group(function () {
    Route::post('forgot', [PasswordResetController::class, 'sendLink'])->middleware('throttle:5,1,mobile-password-forgot')->name('forgot');
    Route::post('reset', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1,mobile-password-reset')->name('reset');
});
