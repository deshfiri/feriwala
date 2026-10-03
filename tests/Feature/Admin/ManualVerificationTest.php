<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\Actions\ConfigureMobileVerificationRequirement;
use App\Domain\Account\Actions\VerifyContactManually;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Exceptions\ActivationBlocked;
use App\Domain\Account\Models\StaffContactVerification;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Models\Payment;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use App\Notifications\Account\ContactVerifiedByStaff;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * Staff confirming an email or mobile on someone's behalf: permission-gated,
 * reasoned, audited, never overwriting evidence, and never a shortcut past
 * KYC, payment or activation.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
});

function manualVerifyOwner(AccountStatus $status = AccountStatus::Registered): User
{
    $account = testBusinessAccount($status);
    $account->owner->forceFill(['mobile' => '+8801712345678', 'email_verified_at' => null, 'mobile_verified_at' => null])->save();

    return $account->owner->fresh();
}

describe('a Client/Partner owner', function () {
    it('confirms the email, marks it staff-verified, audits it and tells the person', function () {
        $owner = manualVerifyOwner();

        $this->actingAs($this->staff)->post(route('admin.accounts.verify-contact', $owner->businessAccount), [
            'channel' => 'email', 'reason' => 'Mailbox filters our link; confirmed by phone.',
        ])->assertSessionHasNoErrors();

        $row = StaffContactVerification::query()->sole();

        expect($owner->fresh()->email_verified_at)->not->toBeNull()
            ->and($row->identity_type)->toBe('user')
            ->and($row->channel)->toBe('email')
            ->and($row->verified_by)->toBe($this->staff->id)
            ->and($row->reason)->toContain('confirmed by phone');

        $audit = AuditLog::query()->where('action', 'identity.email_verified_by_staff')->sole();

        expect($audit->actor_id)->toBe($this->staff->id)
            ->and($audit->reason)->toContain('confirmed by phone')
            ->and($audit->before['email_verified_at'])->toBeNull();

        Notification::assertSentTo($owner, ContactVerifiedByStaff::class, fn ($n) => $n->channel === 'email');
    });

    it('moves the account on to KYC once email and the required mobile are both confirmed', function () {
        $owner = manualVerifyOwner();
        $account = $owner->businessAccount;

        $this->actingAs($this->staff)->post(route('admin.accounts.verify-contact', $account), ['channel' => 'email', 'reason' => 'Confirmed by phone.']);

        // Mobile verification is still required, so the account waits.
        expect($account->fresh()->status)->toBe(AccountStatus::Registered);

        $this->post(route('admin.accounts.verify-contact', $account), ['channel' => 'mobile', 'reason' => 'Confirmed by phone call.']);

        expect($account->fresh()->status)->toBe(AccountStatus::KycPending);
    });

    it('respects the switch that makes mobile verification optional', function () {
        app(ConfigureMobileVerificationRequirement::class)->handle($this->staff, false);
        $owner = manualVerifyOwner();

        $this->actingAs($this->staff)->post(route('admin.accounts.verify-contact', $owner->businessAccount), ['channel' => 'email', 'reason' => 'Confirmed by phone.']);

        expect($owner->businessAccount->fresh()->status)->toBe(AccountStatus::KycPending);
    });

    it('never overwrites an existing verification and records one event per channel', function () {
        $owner = manualVerifyOwner();
        $original = now()->subDay()->startOfSecond();
        $owner->forceFill(['email_verified_at' => $original])->save();

        $this->actingAs($this->staff)->post(route('admin.accounts.verify-contact', $owner->businessAccount), ['channel' => 'email', 'reason' => 'Trying again.'])
            ->assertSessionHasErrors('reason');

        expect($owner->fresh()->email_verified_at->equalTo($original))->toBeTrue()
            ->and(StaffContactVerification::query()->count())->toBe(0);

        $this->post(route('admin.accounts.verify-contact', $owner->businessAccount), ['channel' => 'mobile', 'reason' => 'First time.'])->assertSessionHasNoErrors();
        $this->post(route('admin.accounts.verify-contact', $owner->businessAccount), ['channel' => 'mobile', 'reason' => 'Second time.'])->assertSessionHasErrors('reason');

        expect(StaffContactVerification::query()->count())->toBe(1);
    });

    it('requires a reason and a known channel', function () {
        $owner = manualVerifyOwner();

        $this->actingAs($this->staff)->post(route('admin.accounts.verify-contact', $owner->businessAccount), ['channel' => 'email', 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->post(route('admin.accounts.verify-contact', $owner->businessAccount), ['channel' => 'fax', 'reason' => 'x'])
            ->assertSessionHasErrors('channel');

        expect($owner->fresh()->email_verified_at)->toBeNull();
    });

    it('is refused without account.verify, on staff\'s own business, and to partner sessions', function () {
        $owner = manualVerifyOwner();
        $payload = ['channel' => 'email', 'reason' => 'Confirmed by phone.'];

        $this->actingAs(testPlatformStaff(PlatformRole::OrderManager))->post(route('admin.accounts.verify-contact', $owner->businessAccount), $payload)->assertForbidden();
        $this->actingAs(testBusinessAccount()->owner)->post(route('admin.accounts.verify-contact', $owner->businessAccount), $payload)->assertForbidden();

        expect($owner->fresh()->email_verified_at)->toBeNull();
    });

    it('keeps the verification record append-only', function () {
        $owner = manualVerifyOwner();
        app(VerifyContactManually::class)->handle($this->staff, $owner, 'email', 'Confirmed by phone.');
        $row = StaffContactVerification::query()->sole();

        expect(fn () => DB::table('staff_contact_verifications')->where('id', $row->id)->update(['reason' => 'edited']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('staff_contact_verifications')->where('id', $row->id)->delete())->toThrow(QueryException::class);
    });
});

describe('a Supplier', function () {
    it('confirms email then mobile and moves on to KYC, with the same guarantees', function () {
        $supplier = Supplier::factory()->verificationPending()->create();
        $manager = testPlatformStaff(PlatformRole::SupplierManager);

        $this->actingAs($manager)->post(route('admin.suppliers.verify-contact', $supplier), ['channel' => 'email', 'reason' => 'Confirmed by phone.'])
            ->assertSessionHasNoErrors();

        expect($supplier->fresh()->status)->toBe(SupplierStatus::VerificationPending);

        $this->post(route('admin.suppliers.verify-contact', $supplier), ['channel' => 'mobile', 'reason' => 'Confirmed by phone call.'])
            ->assertSessionHasNoErrors();

        expect($supplier->fresh()->status)->toBe(SupplierStatus::KycPending)
            ->and(StaffContactVerification::query()->where('identity_type', 'supplier')->count())->toBe(2)
            ->and(AuditLog::query()->where('action', 'like', 'identity.%_verified_by_staff')->count())->toBe(2);

        Notification::assertSentTo($supplier, ContactVerifiedByStaff::class);

        $this->post(route('admin.suppliers.verify-contact', $supplier), ['channel' => 'email', 'reason' => 'Again.'])->assertSessionHasErrors('reason');
    });

    it('is refused without supplier.verify and to Supplier sessions', function () {
        $supplier = Supplier::factory()->verificationPending()->create();

        $this->actingAs(testPlatformStaff(PlatformRole::Admin))->post(route('admin.suppliers.verify-contact', $supplier), ['channel' => 'email', 'reason' => 'x'])->assertForbidden();

        expect($supplier->fresh()->email_verified_at)->toBeNull();
    });

    it('keeps a Supplier session out', function () {
        $supplier = Supplier::factory()->verificationPending()->create();
        supplierTestSignIn($supplier);

        $this->post(route('admin.suppliers.verify-contact', $supplier), ['channel' => 'email', 'reason' => 'x'])->assertRedirect(route('login'));

        expect($supplier->fresh()->email_verified_at)->toBeNull();
    });
});

describe('activation is never a shortcut', function () {
    it('refuses to activate an account that has no verified payment, KYC or verification', function () {
        $owner = manualVerifyOwner(AccountStatus::ApprovalPending);

        expect(fn () => app(ActivateAccount::class)->handle($owner->businessAccount, $this->staff->id, 'Staff override attempt.'))
            ->toThrow(ActivationBlocked::class);

        expect($owner->businessAccount->fresh()->status)->toBe(AccountStatus::ApprovalPending);
    });

    it('does not let manual verification fabricate a payment or approve KYC', function () {
        $owner = manualVerifyOwner();
        $account = $owner->businessAccount;

        $this->actingAs($this->staff)->post(route('admin.accounts.verify-contact', $account), ['channel' => 'email', 'reason' => 'Confirmed by phone.']);
        $this->post(route('admin.accounts.verify-contact', $account), ['channel' => 'mobile', 'reason' => 'Confirmed by phone.']);

        expect($account->fresh()->status)->toBe(AccountStatus::KycPending)
            ->and($account->fresh()->isActivated())->toBeFalse()
            ->and(Payment::query()->where('business_account_id', $account->id)->count())->toBe(0);
    });
});
