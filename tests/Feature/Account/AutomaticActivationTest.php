<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\Actions\AdvanceOnboarding;
use App\Domain\Account\Actions\HoldAccountActivation;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Kyc\Actions\ReviewKyc;
use App\Domain\Kyc\Data\KycDecision;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Models\Wallet;
use App\Notifications\Account\AccountActivated;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * Activation is always a person's decision (D39, superseding D27).
 *
 * A settled payment and an approved KYC move an account along onboarding and,
 * once its application is submitted, to the approval gate — never past it. The
 * file keeps its name from D27; what it asserts is the opposite of what it did.
 */

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** What a settled payment or a KYC decision runs. */
function autoActivate(BusinessAccount $account, string $trigger = 'Activation payment settled.'): bool
{
    return app(AdvanceOnboarding::class)->handle($account, $trigger);
}

/** An account whose application is submitted and KYC approved, waiting only on its activation payment. */
function autoActivationApplicantAwaitingPayment(): BusinessAccount
{
    $account = testBusinessAccount(AccountStatus::PaymentVerificationPending);

    KycSubmission::create([
        'business_account_id' => $account->id,
        'status' => KycStatus::Approved,
        'round' => 1,
        'reviewed_at' => now(),
    ]);

    $account->forceFill(['application_submitted_at' => now()])->save();

    return $account;
}

function autoActivationSettlePayment(BusinessAccount $account, PaymentStatus $status = PaymentStatus::Paid): Payment
{
    return Payment::create([
        'business_account_id' => $account->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => $status,
        'amount' => Money::fromDecimal('6000.00', Currency::BDT),
        'currency_code' => 'BDT',
        'completed_at' => $status === PaymentStatus::Paid ? now() : null,
    ]);
}

describe('the platform never activates on its own', function () {
    it('brings a ready account to the approval gate and stops there', function () {
        $account = testAccountReadyForActivation();

        expect(autoActivate($account))->toBeTrue()
            ->and($account->fresh()->status)->toBe(AccountStatus::ApprovalPending)
            ->and($account->fresh()->approval_pending_at)->not->toBeNull()
            ->and(Wallet::query()->where('business_account_id', $account->id)->count())->toBe(0);

        Notification::assertNotSentTo($account->owner, AccountActivated::class);
    });

    it('activates only when a named reviewer approves', function () {
        $account = testAccountReadyForActivation();
        $reviewer = testPlatformStaff(PlatformRole::Admin);

        app(ActivateAccount::class)->handle($account, $reviewer->id, 'Payment, KYC, details and domain checked.');

        $entry = AuditLog::query()->where('action', 'account.activated')->where('auditable_id', $account->id)->sole();

        expect($account->fresh()->status)->toBe(AccountStatus::Active)
            ->and($entry->actor_id)->toBe($reviewer->id)
            ->and($entry->after['automatic'])->toBeFalse()
            ->and(Wallet::query()->where('business_account_id', $account->id)->count())->toBe(1);
    });

    it('keeps an account out of the gate until its application is submitted', function () {
        $account = testAccountReadyForActivation();
        $account->forceFill(['application_submitted_at' => null])->save();

        expect(autoActivate($account))->toBeFalse()
            ->and($account->fresh()->approval_pending_at)->toBeNull();
    });

    it('does nothing while the payment is still outstanding', function () {
        $account = autoActivationApplicantAwaitingPayment();

        expect(autoActivate($account))->toBeFalse()
            ->and($account->fresh()->status)->toBe(AccountStatus::PaymentVerificationPending);
    });

    it('refuses a payment that has not actually settled, or is under reconciliation', function (PaymentStatus $status) {
        $account = autoActivationApplicantAwaitingPayment();
        autoActivationSettlePayment($account, $status);

        expect(autoActivate($account))->toBeFalse()
            ->and($account->fresh()->status)->toBe(AccountStatus::PaymentVerificationPending);
    })->with([
        'pending' => [PaymentStatus::Pending],
        'under reconciliation' => [PaymentStatus::ReconciliationRequired],
    ]);

    it('takes an account back out of the queue when a requirement is reversed', function () {
        $account = testAccountReadyForActivation();

        Payment::query()->where('business_account_id', $account->id)->update(['status' => PaymentStatus::Refunded]);

        expect(autoActivate($account))->toBeFalse()
            ->and($account->fresh()->approval_pending_at)->toBeNull();
    });

    it('arrives at the gate once however many times it is triggered', function () {
        $account = testBusinessAccount(AccountStatus::PaymentVerificationPending);
        KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Approved, 'round' => 1, 'reviewed_at' => now()]);
        autoActivationSettlePayment($account);
        $account->forceFill(['application_submitted_at' => now()])->save();

        autoActivate($account);
        autoActivate($account);
        autoActivate($account);

        expect($account->fresh()->status)->toBe(AccountStatus::ApprovalPending)
            ->and($account->statusHistory()->where('to_status', AccountStatus::ApprovalPending)->count())->toBe(1);
    });
});

describe('the onboarding order of D39', function () {
    it('opens KYC once the package is paid for', function () {
        $account = testBusinessAccount(AccountStatus::PaymentPending);
        autoActivationSettlePayment($account);

        expect(autoActivate($account))->toBeFalse()
            ->and($account->fresh()->status)->toBe(AccountStatus::KycPending);
    });

    it('opens KYC when the activation payment settles through the gateway', function () {
        $settings = app(SettingsRepository::class);
        $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
        $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
        $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

        $account = testBusinessAccount(AccountStatus::PaymentPending);

        $payment = Payment::create([
            'business_account_id' => $account->id,
            'purpose' => PaymentPurpose::Activation,
            'status' => PaymentStatus::Initiated,
            'amount' => Money::fromDecimal('6000.00', Currency::BDT),
            'currency_code' => 'BDT',
            'gateway' => 'sslcommerz',
        ]);

        Http::fake(['*' => Http::response([
            'status' => 'VALID',
            'tran_id' => $payment->reference,
            'currency_amount' => $payment->amount->toDecimal(),
            'currency_type' => 'BDT',
        ])]);

        app(SettlePayment::class)->handle($payment, 'val-'.$payment->id);

        expect($account->fresh()->status)->toBe(AccountStatus::KycPending)
            ->and(Wallet::query()->where('business_account_id', $account->id)->count())->toBe(0);
    });

    it('brings a paid, submitted application to the gate when KYC approval lands last', function () {
        $account = testBusinessAccount(AccountStatus::KycPending);
        autoActivationSettlePayment($account);
        $account->forceFill(['application_submitted_at' => now()])->save();

        $submission = KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::UnderReview,
            'round' => 1,
        ]);
        $account->forceFill(['status' => AccountStatus::KycUnderReview])->save();

        app(ReviewKyc::class)->handle($submission, KycDecision::approve(
            reviewerId: testPlatformStaff(PlatformRole::Admin)->id,
            internalNote: 'Documents check out.',
        ));

        expect($account->fresh()->status)->toBe(AccountStatus::ApprovalPending);
    });
});

describe('an account held for manual review', function () {
    beforeEach(function () {
        $this->staff = testPlatformStaff(PlatformRole::Admin);
        $this->account = testAccountReadyForActivation();

        app(HoldAccountActivation::class)->hold(
            $this->account,
            $this->staff,
            'Bank name does not match the trade licence.',
        );
    });

    it('stays in the review queue, where a person can find it', function () {
        autoActivate($this->account);

        expect($this->account->fresh()->status)->toBe(AccountStatus::ApprovalPending)
            ->and($this->account->fresh()->approval_pending_at)->not->toBeNull()
            ->and($this->account->fresh()->activationIsHeld())->toBeTrue();
    });

    it('can still be activated by hand — the hold summons a reviewer, it does not block one', function () {
        app(ActivateAccount::class)->handle($this->account, $this->staff->id, 'Checked against the licence register.');

        expect($this->account->fresh()->status)->toBe(AccountStatus::Active);
    });
});

describe('placing a hold', function () {
    beforeEach(function () {
        $this->staff = testPlatformStaff(PlatformRole::Admin);
    });

    it('needs a reason, which is recorded against the account', function () {
        $account = testAccountReadyForActivation();

        expect(fn () => app(HoldAccountActivation::class)->hold($account, $this->staff, '   '))
            ->toThrow(InvalidArgumentException::class);

        expect($account->fresh()->activationIsHeld())->toBeFalse();
    });

    it('names who held it and when, and audits it as sensitive', function () {
        $account = testAccountReadyForActivation();

        app(HoldAccountActivation::class)->hold($account, $this->staff, 'Duplicate trade licence number.');

        $account->refresh();

        expect($account->activation_hold_reason)->toBe('Duplicate trade licence number.')
            ->and($account->activation_held_by)->toBe($this->staff->id)
            ->and($account->activation_held_at)->not->toBeNull();

        $entry = AuditLog::query()->where('action', 'account.activation_held')
            ->where('auditable_id', $account->id)->sole();

        expect($entry->actor_id)->toBe($this->staff->id)
            ->and($entry->is_sensitive)->toBeTrue();
    });

    it('refuses to hold an account that is already active', function () {
        $account = testAccountReadyForActivation();
        app(ActivateAccount::class)->handle($account, $this->staff->id, 'Approved.');

        expect(fn () => app(HoldAccountActivation::class)->hold($account->refresh(), $this->staff, 'Too late.'))
            ->toThrow(InvalidArgumentException::class, 'already active');
    });

    it('refuses to release an account that is not held', function () {
        $account = testAccountReadyForActivation();

        expect(fn () => app(HoldAccountActivation::class)->release($account, $this->staff, 'Nothing to release.'))
            ->toThrow(InvalidArgumentException::class, 'not held');
    });

    it('cannot leave a half-written hold behind, even from raw SQL', function () {
        $account = testAccountReadyForActivation();

        expect(fn () => BusinessAccount::query()->where('id', $account->id)
            ->update(['activation_held_at' => now()]))
            ->toThrow(QueryException::class);
    });
});
