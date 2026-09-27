<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\Actions\ActivateAccountAutomatically;
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

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** Runs the automatic path the way a settled payment does. */
function autoActivate(BusinessAccount $account, string $trigger = 'Activation payment settled.'): bool
{
    return app(ActivateAccountAutomatically::class)->handle($account, $trigger);
}

/** An account waiting only on its activation payment. */
function autoActivationApplicantAwaitingPayment(): BusinessAccount
{
    $account = testBusinessAccount(AccountStatus::PaymentVerificationPending);

    KycSubmission::create([
        'business_account_id' => $account->id,
        'status' => KycStatus::Approved,
        'round' => 1,
        'reviewed_at' => now(),
    ]);

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

describe('the normal path', function () {
    it('activates the account with no administrator, once every condition is met', function () {
        $account = testAccountReadyForActivation();

        expect($account->status)->toBe(AccountStatus::ApprovalPending);

        expect(autoActivate($account))->toBeTrue()
            ->and($account->fresh()->status)->toBe(AccountStatus::Active);
    });

    it('records the activation as the platform\'s own, naming no reviewer who never looked', function () {
        $account = testAccountReadyForActivation();
        autoActivate($account);

        $entry = AuditLog::query()
            ->where('action', 'account.activated')
            ->where('auditable_id', $account->id)
            ->sole();

        expect($entry->actor_id)->toBeNull()
            ->and($entry->actor_type)->toBe('system')
            ->and($entry->after['automatic'])->toBeTrue();
    });

    it('gives the account everything a manual activation would', function () {
        $account = testAccountReadyForActivation();
        autoActivate($account);

        // The same ActivateAccount transaction, so §23's "every Active Account
        // has a Wallet" holds on this path too.
        expect(Wallet::query()->where('business_account_id', $account->id)->count())->toBe(1)
            ->and($account->fresh()->approval_pending_at)->toBeNull();

        Notification::assertSentTo($account->owner, AccountActivated::class);
    });

    it('does nothing while a requirement is still outstanding', function () {
        $account = autoActivationApplicantAwaitingPayment();

        expect(autoActivate($account))->toBeFalse()
            ->and($account->fresh()->status)->toBe(AccountStatus::PaymentVerificationPending);
    });

    it('refuses a payment that has not actually settled', function () {
        $account = autoActivationApplicantAwaitingPayment();
        autoActivationSettlePayment($account, PaymentStatus::Pending);

        expect(autoActivate($account))->toBeFalse()
            ->and($account->fresh()->status)->not->toBe(AccountStatus::Active);
    });

    it('refuses a payment still under reconciliation', function () {
        // Money the platform cannot yet account for is not money received.
        $account = autoActivationApplicantAwaitingPayment();
        autoActivationSettlePayment($account, PaymentStatus::ReconciliationRequired);

        expect(autoActivate($account))->toBeFalse()
            ->and($account->fresh()->status)->not->toBe(AccountStatus::Active);
    });

    it('takes an account back out of the queue when a requirement is reversed', function () {
        $account = testAccountReadyForActivation();

        Payment::query()->where('business_account_id', $account->id)
            ->update(['status' => PaymentStatus::Refunded]);

        expect(autoActivate($account))->toBeFalse()
            ->and($account->fresh()->status)->not->toBe(AccountStatus::Active)
            ->and($account->fresh()->approval_pending_at)->toBeNull();
    });
});

describe('arriving more than once', function () {
    it('activates exactly once however many times it is called', function () {
        // One settlement is announced by the IPN, the browser returning and the
        // reconciliation sweep. All three land here.
        $account = testAccountReadyForActivation();

        expect(autoActivate($account))->toBeTrue()
            ->and(autoActivate($account))->toBeTrue()
            ->and(autoActivate($account))->toBeTrue();

        expect($account->fresh()->status)->toBe(AccountStatus::Active)
            ->and($account->statusHistory()->where('to_status', AccountStatus::Active)->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'account.activated')
                ->where('auditable_id', $account->id)->count())->toBe(1)
            ->and(Wallet::query()->where('business_account_id', $account->id)->count())->toBe(1);
    });

    it('reports an already-active account as active rather than failing', function () {
        $account = testAccountReadyForActivation();
        $approver = testPlatformStaff(PlatformRole::Admin);

        app(ActivateAccount::class)->handle($account, $approver->id);

        // A settlement callback arriving after a reviewer got there by hand.
        expect(autoActivate($account->refresh()))->toBeTrue()
            ->and($account->statusHistory()->where('to_status', AccountStatus::Active)->count())->toBe(1);
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

    it('is not activated automatically, however many times the trigger fires', function () {
        expect(autoActivate($this->account))->toBeFalse()
            ->and(autoActivate($this->account))->toBeFalse()
            ->and($this->account->fresh()->status)->toBe(AccountStatus::ApprovalPending);
    });

    it('still reaches the review queue, where a person can find it', function () {
        autoActivate($this->account);

        expect($this->account->fresh()->approval_pending_at)->not->toBeNull()
            ->and($this->account->fresh()->activationIsHeld())->toBeTrue();
    });

    it('says once that it would have been activated and was not', function () {
        autoActivate($this->account);
        autoActivate($this->account);
        autoActivate($this->account);

        $entries = AuditLog::query()
            ->where('action', 'account.activation_held_back')
            ->where('auditable_id', $this->account->id)
            ->get();

        expect($entries)->toHaveCount(1)
            ->and($entries->first()->actor_type)->toBe('system');
    });

    it('can still be activated by hand — the hold summons a reviewer, it does not block one', function () {
        app(ActivateAccount::class)->handle($this->account, $this->staff->id, 'Checked against the licence register.');

        expect($this->account->fresh()->status)->toBe(AccountStatus::Active);

        $entry = AuditLog::query()->where('action', 'account.activated')
            ->where('auditable_id', $this->account->id)->sole();

        expect($entry->actor_id)->toBe($this->staff->id)
            ->and($entry->after['automatic'])->toBeFalse();
    });

    it('activates automatically once the hold is released and the trigger fires again', function () {
        app(HoldAccountActivation::class)->release($this->account, $this->staff, 'Licence register confirms the name.');

        expect($this->account->fresh()->activationIsHeld())->toBeFalse()
            // Releasing is not itself an activation.
            ->and($this->account->fresh()->status)->toBe(AccountStatus::ApprovalPending);

        expect(autoActivate($this->account))->toBeTrue()
            ->and($this->account->fresh()->status)->toBe(AccountStatus::Active);
    });
});

describe('reached from the events that actually trigger it', function () {
    it('activates when the activation payment settles through the gateway', function () {
        // The seam that matters: not the action called directly, but a real
        // settlement running the whole way through SettlePayment.
        $settings = app(SettingsRepository::class);
        $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
        $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
        $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

        $account = autoActivationApplicantAwaitingPayment();

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

        expect($account->fresh()->status)->toBe(AccountStatus::Active)
            ->and(Wallet::query()->where('business_account_id', $account->id)->count())->toBe(1);
    });

    it('activates when the KYC approval is the last condition to land', function () {
        // The other order: paid first, approved second.
        $account = testBusinessAccount(AccountStatus::PaymentVerificationPending);
        autoActivationSettlePayment($account);

        $submission = KycSubmission::create([
            'business_account_id' => $account->id,
            'status' => KycStatus::UnderReview,
            'round' => 1,
        ]);

        app(ReviewKyc::class)->handle($submission, KycDecision::approve(
            reviewerId: testPlatformStaff(PlatformRole::Admin)->id,
            internalNote: 'Documents check out.',
        ));

        expect($account->fresh()->status)->toBe(AccountStatus::Active);
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
        autoActivate($account);

        expect(fn () => app(HoldAccountActivation::class)->hold($account->refresh(), $this->staff, 'Too late.'))
            ->toThrow(InvalidArgumentException::class, 'already active');
    });

    it('refuses to release an account that is not held', function () {
        $account = testAccountReadyForActivation();

        expect(fn () => app(HoldAccountActivation::class)->release($account, $this->staff, 'Nothing to release.'))
            ->toThrow(InvalidArgumentException::class, 'not held');
    });

    it('cannot leave a half-written hold behind, even from raw SQL', function () {
        // The CHECK is the guarantee, not the action's care.
        $account = testAccountReadyForActivation();

        expect(fn () => BusinessAccount::query()->where('id', $account->id)
            ->update(['activation_held_at' => now()]))
            ->toThrow(QueryException::class);
    });
});
