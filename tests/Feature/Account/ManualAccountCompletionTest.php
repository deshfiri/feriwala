<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Models\Payment;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * An administrator completing an account's onboarding by hand.
 *
 * What these protect is that "by hand" still leaves the same evidence as the
 * normal route: a real KYC review, a staff-verified contact, a named waiver in
 * place of a payment that never happened, and an audit record that says who.
 */

/** An applicant part-way through onboarding with nothing yet confirmed. */
function manualCompletionApplicant(AccountStatus $status = AccountStatus::KycPending): BusinessAccount
{
    $account = testBusinessAccount($status);

    $account->owner->forceFill([
        'email_verified_at' => null,
        'mobile_verified_at' => null,
        'mobile' => $account->owner->mobile ?? '01712345678',
    ])->save();

    return $account->refresh();
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();

    $this->superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
});

it('completes the onboarding and activates the account', function () {
    $account = manualCompletionApplicant();

    $this->actingAs($this->superAdmin)
        ->post(route('admin.accounts.complete-manually', $account->public_id), [
            'reason' => 'Paid in person at the Dhaka office; documents seen.',
        ])
        ->assertSessionHasNoErrors();

    $account->refresh();

    expect($account->status)->toBe(AccountStatus::Active)
        ->and($account->owner->email_verified_at)->not->toBeNull()
        ->and($account->owner->mobile_verified_at)->not->toBeNull()
        ->and(KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->where('status', KycStatus::Approved)
            ->exists())->toBeTrue();
});

it('records the waiver against the administrator and invents no payment', function () {
    $account = manualCompletionApplicant();

    $this->actingAs($this->superAdmin)
        ->post(route('admin.accounts.complete-manually', $account->public_id), [
            'reason' => 'Paid in person at the Dhaka office; documents seen.',
        ]);

    $account->refresh();

    expect($account->activationPaymentIsWaived())->toBeTrue()
        ->and($account->activation_payment_waived_by)->toBe($this->superAdmin->id)
        ->and($account->activation_payment_waiver_reason)->toContain('Dhaka office')
        // No money moved, so nothing may claim it did.
        ->and(Payment::query()->where('business_account_id', $account->id)->count())->toBe(0);

    expect(DB::table('audit_logs')->where('action', 'account.completed_manually')->exists())->toBeTrue();
});

it('carries on from an account already part-way through', function () {
    $account = manualCompletionApplicant(AccountStatus::PackageSelectionPending);

    $this->actingAs($this->superAdmin)
        ->post(route('admin.accounts.complete-manually', $account->public_id), [
            'reason' => 'Vouched for by the regional manager.',
        ])
        ->assertSessionHasNoErrors();

    expect($account->refresh()->status)->toBe(AccountStatus::Active);
});

it('requires a reason', function () {
    $account = manualCompletionApplicant();

    $this->actingAs($this->superAdmin)
        ->post(route('admin.accounts.complete-manually', $account->public_id), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($account->refresh()->status)->toBe(AccountStatus::KycPending)
        ->and($account->activationPaymentIsWaived())->toBeFalse();
});

it('refuses an account that is already active', function () {
    $account = testBusinessAccount();

    $this->actingAs($this->superAdmin)
        ->post(route('admin.accounts.complete-manually', $account->public_id), [
            'reason' => 'Trying to complete an active account.',
        ])
        ->assertSessionHasErrors('reason');
});

it('is refused to staff without the authority to approve and verify', function () {
    $account = manualCompletionApplicant();
    $staff = testPlatformStaff(PlatformRole::ContentManager);

    $this->actingAs($staff)
        ->post(route('admin.accounts.complete-manually', $account->public_id), [
            'reason' => 'Should not be allowed to do this.',
        ])
        ->assertForbidden();

    expect($account->refresh()->status)->toBe(AccountStatus::KycPending);
});
