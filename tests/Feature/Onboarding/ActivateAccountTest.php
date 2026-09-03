<?php

use App\Domain\Account\Actions\ActivateAccount;
use App\Domain\Account\ActivationRequirements;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Exceptions\ActivationBlocked;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create();

    $this->package = Package::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'fee_minor' => 500000,
        'validity_days' => 365,
        'grace_period_days' => 14,
    ]);

    $this->applicant = User::factory()->create([
        'status' => AccountStatus::ApprovalPending,
        'email_verified_at' => now(),
        'mobile_verified_at' => now(),
    ]);
});

function approveKyc(): void
{
    KycSubmission::create([
        'user_id' => test()->applicant->id,
        'status' => KycStatus::Approved,
        'round' => 1,
    ]);
}

function settleActivationPayment(): Payment
{
    return Payment::create([
        'user_id' => test()->applicant->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => PaymentStatus::Paid,
        'amount_minor' => 600000,
        'currency_code' => 'BDT',
    ]);
}

function pendingSubscription(): UserPackage
{
    return UserPackage::create([
        'user_id' => test()->applicant->id,
        'package_id' => test()->package->id,
        'status' => UserPackageStatus::PendingPayment,
    ]);
}

function activate(?string $note = null): User
{
    return app(ActivateAccount::class)->handle(test()->applicant, test()->admin->id, $note);
}

describe('the three conditions (§5.1, §44)', function () {
    it('activates when all three are met', function () {
        approveKyc();
        settleActivationPayment();

        activate();

        expect($this->applicant->fresh()->status)->toBe(AccountStatus::Active)
            ->and($this->applicant->fresh()->activated_at)->not->toBeNull();
    });

    it('refuses without KYC approval', function () {
        settleActivationPayment();

        expect(fn () => activate())
            ->toThrow(ActivationBlocked::class, 'KYC has not been approved');
    });

    it('refuses without a settled activation payment', function () {
        approveKyc();

        expect(fn () => activate())
            ->toThrow(ActivationBlocked::class, 'activation payment has not been verified');
    });

    it('refuses when the payment is only pending', function () {
        // "Pending" looks like success on a gateway redirect and is not.
        approveKyc();

        Payment::create([
            'user_id' => $this->applicant->id,
            'purpose' => PaymentPurpose::Activation,
            'status' => PaymentStatus::Pending,
            'amount_minor' => 600000,
            'currency_code' => 'BDT',
        ]);

        expect(fn () => activate())->toThrow(ActivationBlocked::class);
    });

    it('refuses when email or mobile is unverified', function () {
        approveKyc();
        settleActivationPayment();

        $this->applicant->forceFill(['mobile_verified_at' => null])->save();

        expect(fn () => activate())
            ->toThrow(ActivationBlocked::class, 'not both verified');
    });

    it('reports every unmet condition, not just the first', function () {
        // So an administrator fixes everything at once rather than being
        // refused repeatedly.
        $reasons = app(ActivationRequirements::class)->unmet($this->applicant);

        expect($reasons)->toHaveCount(2);
    });

    it('leaves the account untouched when it refuses', function () {
        settleActivationPayment();

        try {
            activate();
        } catch (ActivationBlocked) {
            // expected
        }

        expect($this->applicant->fresh()->status)->toBe(AccountStatus::ApprovalPending)
            ->and($this->applicant->fresh()->activated_at)->toBeNull();
    });
});

describe('administrative approval', function () {
    it('records who approved it', function () {
        approveKyc();
        settleActivationPayment();

        activate();

        $history = $this->applicant->statusHistory()->first();

        expect($history->to_status)->toBe(AccountStatus::Active)
            ->and($history->changed_by)->toBe($this->admin->id)
            ->and($history->reason)->toBe('Activation approved.');
    });

    it('refuses to activate a suspended account', function () {
        approveKyc();
        settleActivationPayment();
        $this->applicant->forceFill(['status' => AccountStatus::Suspended])->save();

        expect(fn () => activate())->toThrow(ActivationBlocked::class, 'Suspended');
    });

    it('refuses to activate an already active account', function () {
        approveKyc();
        settleActivationPayment();
        activate();

        expect(fn () => activate())->toThrow(ActivationBlocked::class, 'already active');
    });
});

describe('the subscription', function () {
    it('goes live with the account', function () {
        approveKyc();
        settleActivationPayment();
        $subscription = pendingSubscription();

        activate();

        expect($subscription->fresh()->status)->toBe(UserPackageStatus::Active)
            ->and($this->applicant->fresh()->current_user_package_id)->toBe($subscription->id);
    });

    it('starts its term at activation, not at purchase', function () {
        // Someone whose approval sat in a queue for three days should not lose
        // three days of the package they paid for.
        approveKyc();
        settleActivationPayment();
        pendingSubscription();

        activate();

        $subscription = $this->applicant->fresh()->currentPackage;

        expect($subscription->started_at->isToday())->toBeTrue()
            ->and($subscription->started_at->diffInDays($subscription->expires_at))->toBe(365.0);
    });

    it('adds the grace period beyond expiry', function () {
        approveKyc();
        settleActivationPayment();
        pendingSubscription();

        activate();

        $subscription = $this->applicant->fresh()->currentPackage;

        expect($subscription->expires_at->diffInDays($subscription->grace_ends_at))->toBe(14.0);
    });

    it('activates without a subscription rather than failing', function () {
        // An administratively granted account may have no package yet.
        approveKyc();
        settleActivationPayment();

        activate();

        expect($this->applicant->fresh()->status)->toBe(AccountStatus::Active);
    });
});

it('is the only route into Active', function () {
    // §5.3: approval is the sole gateway. Nothing else transitions to Active.
    $routesIn = array_filter(
        AccountStatus::cases(),
        fn (AccountStatus $s) => in_array(AccountStatus::Active, $s->transitionsTo(), true)
            && $s->isOnboarding(),
    );

    expect(array_values($routesIn))->toBe([AccountStatus::ApprovalPending]);
});
