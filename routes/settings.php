<?php

use App\Http\Controllers\Erp\StaffController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
 * The business gate applies here too. Profile and security are two of the seven
 * §5.4 areas and pass its allow-list; staff management does not, and would
 * otherwise be reachable by an account that has not paid for the package whose
 * staff limit governs it.
 */
Route::middleware(['auth', 'business.activated'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified', 'business.activated'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    /*
     * Staff (§8.1, D1).
     *
     * No account segment. Every route here resolves the account from the
     * signed-in person's membership, so there is no identifier to swap for
     * somebody else's — and no switcher, because there is nothing to switch
     * between. Staff are addressed by their own public id and looked up inside
     * the caller's account.
     */
    Route::get('settings/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('settings/staff/invitations', [StaffController::class, 'invite'])
        ->name('staff.invitations.store');
    Route::delete('settings/staff/invitations/{invitation}', [StaffController::class, 'revokeInvitation'])
        ->name('staff.invitations.destroy');
    Route::patch('settings/staff/{staff}', [StaffController::class, 'updateRole'])
        ->name('staff.update');
    Route::delete('settings/staff/{staff}', [StaffController::class, 'remove'])
        ->name('staff.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
