<?php

use App\Domain\Billing\Actions\CalculateActivationQuote;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Package\Models\Package;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Money;
use App\Support\References\Reference;
use App\Support\References\ReferencePrefix;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;

beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('billing.registration_fee', 'billing', SettingType::Money, 100000);
    $settings->define('billing.tax_rate_percent', 'billing', SettingType::Decimal, '15');
    $settings->define('billing.gateway_charge_percent', 'billing', SettingType::Decimal, '0');

    $this->account = testBusinessAccount();
    $this->user = $this->account->owner;

    $this->package = Package::create([
        'name' => 'Growth',
        'slug' => 'growth',
        'fee_minor' => 500000,
    ]);
});

function recordActivation(?string $key = null, ?Money $deposit = null): Payment
{
    $quote = app(CalculateActivationQuote::class)->handle(
        test()->package,
        walletDeposit: $deposit,
    );

    return app(RecordPaymentFromQuote::class)->handle(
        test()->account,
        $quote,
        PaymentPurpose::Activation,
        idempotencyKey: $key,
        payable: test()->package,
    );
}

describe('recording', function () {
    it('stores the payment with a human-readable reference', function () {
        $payment = recordActivation();

        expect($payment->reference)->not->toBeNull()
            ->and(Reference::matches($payment->reference, ReferencePrefix::Payment))->toBeTrue()
            ->and($payment->public_id)->not->toBeNull()
            ->and($payment->status)->toBe(PaymentStatus::Draft);
    });

    it('keeps every component as its own allocation (§5.1)', function () {
        $payment = recordActivation(deposit: Money::of(1000000));

        expect($payment->allocations)->toHaveCount(4)
            ->and($payment->allocatedTo(AllocationType::RegistrationFee)->minorUnits)->toBe(100000)
            ->and($payment->allocatedTo(AllocationType::PackageFee)->minorUnits)->toBe(500000)
            ->and($payment->allocatedTo(AllocationType::Tax)->minorUnits)->toBe(90000)
            ->and($payment->allocatedTo(AllocationType::WalletDeposit)->minorUnits)->toBe(1000000);
    });

    it('makes the two fees separately reportable', function () {
        // The point of §5.1: "how much registration fee did we take" must be
        // answerable without unpicking totals.
        recordActivation();

        $registrationTotal = Payment::query()
            ->join('payment_allocations', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.type', AllocationType::RegistrationFee->value)
            ->sum('payment_allocations.amount_minor');

        expect((int) $registrationTotal)->toBe(100000);
    });

    it('records revenue separately from the amount charged', function () {
        // A deposit is the partner's money, not Feriwala's earnings.
        $payment = recordActivation(deposit: Money::of(1000000));

        expect($payment->amount_minor->minorUnits)->toBe(1690000)
            ->and($payment->revenue_minor->minorUnits)->toBe(600000);
    });

    it('preserves the order the user saw at checkout', function () {
        $payment = recordActivation(deposit: Money::of(1000000));

        expect($payment->allocations->pluck('type')->map->value->all())->toBe([
            'registration_fee',
            'package_fee',
            'tax',
            'wallet_deposit',
        ]);
    });

    it('links the payment to what it is paying for', function () {
        $payment = recordActivation();

        expect($payment->payable->is($this->package))->toBeTrue();
    });

    it('has allocations that sum to the amount charged', function () {
        $payment = recordActivation(deposit: Money::of(1000000));

        expect($payment->allocationsBalance())->toBeTrue();
    });
});

describe('duplicate prevention (§26.4)', function () {
    it('returns the same payment when a key is replayed', function () {
        $first = recordActivation('activation-key-1');
        $second = recordActivation('activation-key-1');

        expect($second->id)->toBe($first->id)
            ->and(Payment::count())->toBe(1);
    });

    it('does not duplicate the allocations on replay', function () {
        recordActivation('activation-key-1');
        $second = recordActivation('activation-key-1');

        expect($second->allocations()->count())->toBe(3);
    });

    it('creates separate payments for separate keys', function () {
        recordActivation('key-a');
        recordActivation('key-b');

        expect(Payment::count())->toBe(2);
    });

    it('allows payments with no key at all', function () {
        recordActivation();
        recordActivation();

        expect(Payment::count())->toBe(2);
    });
});

describe('status lifecycle', function () {
    it('moves from draft through to paid', function () {
        $payment = recordActivation();

        $payment->transitionTo(PaymentStatus::Initiated);
        $payment->transitionTo(PaymentStatus::Paid);

        expect($payment->status)->toBe(PaymentStatus::Paid)
            ->and($payment->isSettled())->toBeTrue();
    });

    it('refuses to mark a draft paid without initiating it', function () {
        $payment = recordActivation();

        expect(fn () => $payment->transitionTo(PaymentStatus::Paid))
            ->toThrow(IllegalStateTransition::class);
    });

    it('never lets settled money un-arrive', function () {
        $payment = recordActivation();
        $payment->transitionTo(PaymentStatus::Initiated);
        $payment->transitionTo(PaymentStatus::Paid);

        expect(fn () => $payment->transitionTo(PaymentStatus::Failed))
            ->toThrow(IllegalStateTransition::class)
            ->and(fn () => $payment->transitionTo(PaymentStatus::Pending))
            ->toThrow(IllegalStateTransition::class);
    });

    it('does not revive a failed payment', function () {
        // A retry is a new attempt — the gateway reference belongs to the
        // failed one.
        $payment = recordActivation();
        $payment->transitionTo(PaymentStatus::Initiated);
        $payment->transitionTo(PaymentStatus::Failed);

        expect($payment->currentState()->transitionsTo())->toBe([])
            ->and(fn () => $payment->transitionTo(PaymentStatus::Paid))
            ->toThrow(IllegalStateTransition::class);
    });

    it('treats pending as not yet settled', function () {
        // "Pending" looks like success on a gateway redirect page and is not.
        $payment = recordActivation();
        $payment->transitionTo(PaymentStatus::Initiated);
        $payment->transitionTo(PaymentStatus::Pending);

        expect($payment->isSettled())->toBeFalse();
    });
});

it('hides the idempotency key from serialised output', function () {
    $payment = recordActivation('secret-key');

    expect($payment->toArray())->not->toHaveKey('idempotency_key');
});
