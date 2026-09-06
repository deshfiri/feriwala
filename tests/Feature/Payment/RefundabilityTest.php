<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Actions\DecideRefund;
use App\Domain\Billing\Actions\RequestRefund;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\Refundability;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\RefundabilityPolicy;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Models\User;

/*
 * Fee refundability (P1-73, D17).
 *
 * The rules that matter are the defaults and the moment they stop applying: a
 * registration fee that is never refundable, a package fee that stops being
 * refundable the day the business starts trading, and a decision that is
 * re-checked when it is taken rather than trusted from when it was asked for.
 */

function refundTestPayment(
    ?AccountStatus $accountStatus = null,
    PaymentStatus $status = PaymentStatus::Paid,
): Payment {
    $account = $accountStatus === null
        ? testBusinessAccount()
        : testBusinessAccount($accountStatus);

    $payment = Payment::create([
        'business_account_id' => $account->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => $status,
        'amount_minor' => 600000,
        'revenue_minor' => 600000,
        'currency_code' => 'BDT',
        'completed_at' => $status->isSettled() ? now() : null,
    ]);

    $payment->allocations()->create([
        'type' => AllocationType::RegistrationFee,
        'amount_minor' => 100000,
        'currency_code' => 'BDT',
        'sort_order' => 0,
    ]);

    $payment->allocations()->create([
        'type' => AllocationType::PackageFee,
        'amount_minor' => 500000,
        'currency_code' => 'BDT',
        'sort_order' => 1,
    ]);

    return $payment->load(['allocations', 'businessAccount']);
}

describe('the defaults (D17)', function () {
    it('never refunds the registration fee', function () {
        // It pays for work already done at the moment of registering.
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);

        $eligibility = app(RefundabilityPolicy::class)
            ->evaluate($payment, AllocationType::RegistrationFee);

        expect($eligibility->rule)->toBe(Refundability::Never)
            ->and($eligibility->isRefundable)->toBeFalse()
            ->and($eligibility->reason)->toContain('not refundable');
    });

    it('refunds the package fee before activation', function () {
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);

        $eligibility = app(RefundabilityPolicy::class)
            ->evaluate($payment, AllocationType::PackageFee);

        expect($eligibility->rule)->toBe(Refundability::BeforeActivation)
            ->and($eligibility->isRefundable)->toBeTrue()
            ->and($eligibility->refundableAmount->minorUnits)->toBe(500000)
            // Never automatic: D17 makes every refund an administrative decision.
            ->and($eligibility->requiresApproval())->toBeTrue();
    });

    it('stops refunding the package fee once the business is trading', function () {
        // The account has had what it paid for. Unwinding that is a closure
        // question with its own retention rules (D18), not a refund.
        $payment = refundTestPayment(AccountStatus::Active);

        $eligibility = app(RefundabilityPolicy::class)
            ->evaluate($payment, AllocationType::PackageFee);

        expect($eligibility->isRefundable)->toBeFalse()
            ->and($eligibility->reason)->toContain('activated');
    });

    it('returns a wallet deposit, which was never our money', function () {
        // Refusing by default would be refusing to hand back something held on
        // the partner's behalf (§24.4).
        expect(Refundability::defaultFor(AllocationType::WalletDeposit))
            ->toBe(Refundability::Always);
    });

    it('refuses anything nobody has configured', function () {
        // A misconfiguration should refuse a refund a human can then grant,
        // rather than pay out money nobody agreed to.
        expect(Refundability::defaultFor(AllocationType::GatewayCharge))
            ->toBe(Refundability::Never);
    });
});

describe('configuration', function () {
    it('lets an administrator make the registration fee refundable', function () {
        $settings = app(SettingsRepository::class);
        $settings->define(
            RefundabilityPolicy::SETTING_PREFIX.AllocationType::RegistrationFee->value,
            'billing',
            SettingType::String,
            Refundability::Always->value,
        );

        $payment = refundTestPayment(AccountStatus::Active);

        expect(app(RefundabilityPolicy::class)->evaluate($payment, AllocationType::RegistrationFee)->isRefundable)
            ->toBeTrue();
    });

    it('falls back to the default rather than breaking on a bad stored value', function () {
        // A typo in a settings row must not take down a refund screen, and the
        // fallback is the safe direction.
        $settings = app(SettingsRepository::class);
        $settings->define(
            RefundabilityPolicy::SETTING_PREFIX.AllocationType::PackageFee->value,
            'billing',
            SettingType::String,
            'sometimes-maybe',
        );

        expect(app(RefundabilityPolicy::class)->ruleFor(AllocationType::PackageFee))
            ->toBe(Refundability::BeforeActivation);
    });
});

describe('what cannot be refunded at all', function () {
    it('refuses when the money never arrived', function () {
        // Cancelling an unsettled payment is a different action; conflating
        // them issues a refund against money that never came in.
        $payment = refundTestPayment(AccountStatus::PaymentPending, PaymentStatus::Pending);

        $eligibility = app(RefundabilityPolicy::class)
            ->evaluate($payment, AllocationType::PackageFee);

        expect($eligibility->isRefundable)->toBeFalse()
            ->and($eligibility->reason)->toContain('not been settled');
    });

    it('refuses a component the payment does not carry', function () {
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);

        expect(app(RefundabilityPolicy::class)->evaluate($payment, AllocationType::DomainCharge)->isRefundable)
            ->toBeFalse();
    });
});

describe('the whole payment', function () {
    it('lists every component, refusals included', function () {
        // An administrator needs to see that the registration fee exists and is
        // not coming back, not have it quietly absent.
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);

        $all = app(RefundabilityPolicy::class)->evaluateAll($payment);

        expect($all)->toHaveCount(2)
            ->and(collect($all)->filter(fn ($e) => $e->isRefundable))->toHaveCount(1);
    });

    it('totals only what could actually be given back', function () {
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);

        expect(app(RefundabilityPolicy::class)->refundableTotal($payment)->minorUnits)
            ->toBe(500000);
    });
});

describe('requesting', function () {
    it('records the request with the rule that applied', function () {
        // A policy change later must not rewrite the basis a past decision was
        // taken on.
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);
        $actor = User::factory()->staff()->create();

        $request = app(RequestRefund::class)->handle(
            $payment,
            AllocationType::PackageFee,
            $actor,
            'Customer changed their mind.',
        );

        expect($request->status)->toBe(RefundStatus::Requested)
            ->and($request->refundability)->toBe(Refundability::BeforeActivation)
            ->and($request->amount_minor->minorUnits)->toBe(500000);

        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.refund_requested']);
    });

    it('refuses a request with no stated grounds', function () {
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);

        expect(fn () => app(RequestRefund::class)->handle(
            $payment,
            AllocationType::PackageFee,
            User::factory()->staff()->create(),
            '   ',
        ))->toThrow(InvalidArgumentException::class, 'needs a reason');
    });

    it('refuses a request for something that is not refundable', function () {
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);

        expect(fn () => app(RequestRefund::class)->handle(
            $payment,
            AllocationType::RegistrationFee,
            User::factory()->staff()->create(),
            'Asked nicely.',
        ))->toThrow(InvalidArgumentException::class);
    });

    it('allows only one open request per component', function () {
        // Two administrators asking at once would otherwise leave the account
        // owed the money twice.
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);
        $actor = User::factory()->staff()->create();
        $action = app(RequestRefund::class);

        $action->handle($payment, AllocationType::PackageFee, $actor, 'First.');

        expect(fn () => $action->handle($payment, AllocationType::PackageFee, $actor, 'Second.'))
            ->toThrow(InvalidArgumentException::class, 'already awaiting a decision');
    });

    it('allows a fresh request once an earlier one was refused', function () {
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);
        $actor = User::factory()->staff()->create();

        $first = app(RequestRefund::class)->handle($payment, AllocationType::PackageFee, $actor, 'First.');
        app(DecideRefund::class)->reject($first, $actor, 'Not on these grounds.');

        $second = app(RequestRefund::class)->handle($payment, AllocationType::PackageFee, $actor, 'New grounds.');

        expect($second->id)->not->toBe($first->id);
    });
});

describe('deciding', function () {
    it('approves without moving money', function () {
        // Approval is a decision; processing is money leaving. A gateway refund
        // can be approved on Monday and fail on Tuesday.
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);
        $actor = User::factory()->staff()->create();

        $request = app(RequestRefund::class)->handle($payment, AllocationType::PackageFee, $actor, 'Reason.');
        $decided = app(DecideRefund::class)->approve($request, $actor, 'Verified with the customer.');

        expect($decided->status)->toBe(RefundStatus::Approved)
            ->and($decided->decided_at)->not->toBeNull()
            ->and($decided->status->isTerminal())->toBeFalse();

        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.refund_approved']);
    });

    it('re-checks eligibility at the moment of approval', function () {
        /*
         * The failure this guards: the account is activated between the request
         * and the decision. Trusting the request would pay out against a rule
         * that had already stopped applying.
         */
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);
        $actor = User::factory()->staff()->create();

        $request = app(RequestRefund::class)->handle($payment, AllocationType::PackageFee, $actor, 'Reason.');

        $payment->businessAccount->forceFill([
            'status' => AccountStatus::Active,
            'activated_at' => now(),
        ])->save();

        expect(fn () => app(DecideRefund::class)->approve($request, $actor))
            ->toThrow(InvalidArgumentException::class, 'activated');
    });

    it('keeps a rejection, with its reason', function () {
        // "We refused this in March and here is why" is what a chargeback
        // dispute needs.
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);
        $actor = User::factory()->staff()->create();

        $request = app(RequestRefund::class)->handle($payment, AllocationType::PackageFee, $actor, 'Reason.');
        $decided = app(DecideRefund::class)->reject($request, $actor, 'Outside the published window.');

        expect($decided->status)->toBe(RefundStatus::Rejected)
            ->and($decided->decision_note)->toBe('Outside the published window.');

        $this->assertDatabaseHas('refund_requests', ['id' => $request->id]);
    });

    it('refuses a rejection with no explanation', function () {
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);
        $actor = User::factory()->staff()->create();

        $request = app(RequestRefund::class)->handle($payment, AllocationType::PackageFee, $actor, 'Reason.');

        expect(fn () => app(DecideRefund::class)->reject($request, $actor, ' '))
            ->toThrow(InvalidArgumentException::class, 'needs a reason');
    });

    it('lets one decision win when two administrators decide at once', function () {
        $payment = refundTestPayment(AccountStatus::PaymentVerificationPending);
        $actor = User::factory()->staff()->create();
        $decide = app(DecideRefund::class);

        $request = app(RequestRefund::class)->handle($payment, AllocationType::PackageFee, $actor, 'Reason.');

        $decide->approve($request, $actor);

        // The loser is told, rather than silently overwriting a decision taken.
        expect(fn () => $decide->reject($request->fresh(), $actor, 'Too late.'))
            ->toThrow(InvalidArgumentException::class, 'already been decided');
    });
});

describe('the status machine', function () {
    it('keeps approval and payment apart', function () {
        expect(RefundStatus::Approved->transitionsTo())
            ->toBe([RefundStatus::Processed, RefundStatus::Failed]);
    });

    it('lets a failed refund be retried without being decided again', function () {
        expect(RefundStatus::Failed->transitionsTo())->toBe([RefundStatus::Processed]);
    });

    it('closes a rejected or processed refund', function (RefundStatus $status) {
        expect($status->isTerminal())->toBeTrue()
            ->and($status->transitionsTo())->toBe([]);
    })->with([RefundStatus::Rejected, RefundStatus::Processed]);
});
