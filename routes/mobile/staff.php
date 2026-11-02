<?php

use App\Http\Controllers\Api\Mobile\V1\Staff\StaffController;
use App\Http\Controllers\Api\Mobile\V1\Staff\StaffInvitationController;
use Illuminate\Support\Facades\Route;

// Joining someone else's business needs no business of one's own, so the
// invitation sits outside `mobile.activated` (§5.4 `staff.invitation.`).
Route::prefix('staff/invitation')->name('staff.invitation.')->group(function () {
    Route::get('{token}', [StaffInvitationController::class, 'show'])->name('show');
    Route::post('{token}', [StaffInvitationController::class, 'accept'])
        ->middleware('throttle:10,1')
        ->name('accept');
});

// Managing the account's own staff: an activated business whose package has
// staff at all (`entitled:staff_limit`, as on the web).
Route::middleware(['mobile.activated', 'entitled:staff_limit'])->prefix('staff')->name('staff.')->group(function () {
    Route::get('/', [StaffController::class, 'index'])->name('index');
    Route::post('invitations', [StaffController::class, 'invite'])->name('invitations.store');
    Route::delete('invitations/{invitation}', [StaffController::class, 'revokeInvitation'])->name('invitations.destroy');
    Route::patch('{staff}', [StaffController::class, 'updateRole'])->name('update');
    Route::delete('{staff}', [StaffController::class, 'remove'])->name('destroy');
});
