<?php

use App\Http\Controllers\Api\Mobile\V1\Account\AccountDeletionController;
use App\Http\Controllers\Api\Mobile\V1\Account\EmailVerificationController;
use App\Http\Controllers\Api\Mobile\V1\Account\LocaleController;
use App\Http\Controllers\Api\Mobile\V1\Account\MobileVerificationController;
use App\Http\Controllers\Api\Mobile\V1\Account\NotificationController;
use App\Http\Controllers\Api\Mobile\V1\Account\PasswordController;
use App\Http\Controllers\Api\Mobile\V1\Account\ProfileController;
use App\Http\Controllers\Api\Mobile\V1\Account\SecurityController;
use App\Http\Controllers\Api\Mobile\V1\Account\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
 * The signed-in person's own account: profile, security, verification,
 * notifications and language. Every one is on the web gate's pre-activation
 * allow-list, so none sits inside `mobile.activated`.
 *
 * Where the web asks for the password again (RequirePassword), the request
 * carries `current_password` instead: a token client has no session to remember
 * a confirmation in.
 */

Route::prefix('profile')->name('profile.')->group(function () {
    Route::get('/', [ProfileController::class, 'show'])->name('show');
    Route::patch('/', [ProfileController::class, 'update'])->name('update');
});

Route::prefix('verification')->name('verification.')->group(function () {
    Route::post('email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1,mobile-email-resend')->name('email.resend');
    Route::get('mobile', [MobileVerificationController::class, 'show'])->name('mobile.show');
    Route::post('mobile/code', [MobileVerificationController::class, 'send'])
        ->middleware('throttle:3,1,mobile-sms-code')->name('mobile.send');
    Route::post('mobile', [MobileVerificationController::class, 'verify'])
        ->middleware('throttle:10,1,mobile-sms-verify')->name('mobile.verify');
});

Route::prefix('security')->name('security.')->group(function () {
    Route::get('/', [SecurityController::class, 'show'])->name('show');
    Route::put('password', [PasswordController::class, 'update'])
        ->middleware('throttle:5,1,mobile-password-change')->name('password.update');

    Route::get('sessions', [SecurityController::class, 'sessions'])->name('sessions.index');
    Route::delete('sessions', [SecurityController::class, 'purgeSessions'])->name('sessions.purge');
    Route::delete('sessions/{session}', [SecurityController::class, 'destroySession'])->name('sessions.destroy');

    Route::prefix('two-factor')->name('two-factor.')->middleware('throttle:10,1,mobile-two-factor-settings')->group(function () {
        Route::post('/', [TwoFactorController::class, 'enable'])->name('enable');
        Route::post('confirm', [TwoFactorController::class, 'confirm'])->name('confirm');
        Route::delete('/', [TwoFactorController::class, 'disable'])->name('disable');
        Route::post('recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('recovery-codes.regenerate');
    });
});

Route::prefix('account-deletion')->name('account-deletion.')->group(function () {
    Route::get('/', [AccountDeletionController::class, 'show'])->name('show');
    Route::post('/', [AccountDeletionController::class, 'store'])->middleware('throttle:5,1,mobile-account-deletion')->name('store');
    Route::delete('/', [AccountDeletionController::class, 'destroy'])->name('destroy');
});

Route::prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::post('read', [NotificationController::class, 'readAll'])->name('read-all');
    Route::post('{notification}/read', [NotificationController::class, 'read'])->name('read');
});

Route::prefix('locale')->name('locale.')->group(function () {
    Route::get('/', [LocaleController::class, 'show'])->name('show');
    Route::put('/', [LocaleController::class, 'update'])->name('update');
});
