<?php

use App\Http\Controllers\Erp\PackageChangeController;
use App\Http\Controllers\Erp\RenewalController;
use App\Http\Controllers\Erp\StaffController;
use App\Http\Controllers\Erp\SubscriptionController;
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

    /*
     * The account's own subscription (§8.2).
     *
     * In the first group, which is not gated on `verified`, and named
     * `subscription.` so the §5.4 allow-list can carry it: "what did I choose
     * and what will it cost" is a question an applicant asks *before* paying,
     * and a screen that appears only after the money clears answers it too
     * late.
     */
    Route::get('settings/subscription', [SubscriptionController::class, 'show'])
        ->name('subscription.show');

    /*
     * Renewing a term (§8.2, §8.4).
     *
     * Named under `subscription.` so it passes the §5.4 allow-list: §8.4 keeps
     * "limited access to Payment and renewal modules" open after a term has run
     * out, and a renewal page that closes at expiry closes exactly when it is
     * needed. Self-scoped — the account comes from the membership, so there is
     * no subscription identifier in either URL.
     */
    /*
     * Cancelling a term (§8.2). In the same group as renewal, because an
     * account whose package has lapsed may want to end it rather than renew,
     * and both are reached from the same screen.
     */
    Route::post('settings/subscription/cancel', [SubscriptionController::class, 'cancel'])
        ->name('subscription.cancel');

    Route::get('settings/subscription/renew', [RenewalController::class, 'show'])
        ->name('subscription.renew.show');
    Route::post('settings/subscription/renew', [RenewalController::class, 'pay'])
        ->name('subscription.renew.pay');

    /*
     * Moving to another package (§8.3). Same group and the same reasoning: an
     * account whose term is running out may need to move plan as much as renew,
     * and both are payment paths §8.4 keeps open.
     */
    Route::get('settings/subscription/change', [PackageChangeController::class, 'index'])
        ->name('subscription.change.index');
    Route::post('settings/subscription/change', [PackageChangeController::class, 'store'])
        ->name('subscription.change.store');

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

    /*
     * Ending sessions elsewhere (§6).
     *
     * Behind password confirmation, like the screen they are reached from:
     * someone who walks up to an unattended laptop must not be able to sign the
     * owner out of everywhere else and keep the one session they are sitting at.
     */
    Route::delete('settings/security/sessions', [SecurityController::class, 'destroyOtherSessions'])
        ->middleware(RequirePassword::class)
        ->name('security.sessions.purge');

    Route::delete('settings/security/sessions/{session}', [SecurityController::class, 'destroySession'])
        ->middleware(RequirePassword::class)
        ->name('security.sessions.destroy');

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
    /*
     * Staff is a package facility (§8.1), so it carries the entitlement gate as
     * well as the policies. The policies answer "may *this person* manage
     * staff"; the gate answers "did this account buy staff at all" — different
     * questions, and hiding the nav entry answers neither to somebody who types
     * the URL (P1-11).
     */
    Route::middleware('entitled:staff_limit')->group(function () {
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
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
