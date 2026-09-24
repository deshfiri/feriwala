<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Data\PaymentReceipt;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Models\RefundRequest;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Payment receipts (P2-32, §26.4).
 *
 * An invoice says what was owed; a receipt says what was actually taken. Four
 * rules carry this file:
 *
 *   - built only from stored verified facts, never from a redirect;
 *   - a stable, unique number;
 *   - no credentials, signatures, internal notes or raw payloads;
 *   - an account sees only its own.
 */

function receiptPayment(array $attributes = []): Payment
{
    $account = test()->account;

    $payment = Payment::create([
        'business_account_id' => $account->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => PaymentStatus::Paid,
        'currency_code' => 'BDT',
        'amount' => Money::fromDecimal('6000.00', Currency::BDT),
        'gateway' => 'sslcommerz',
        'gateway_reference' => 'val-1',
        'gateway_mode' => 'live',
        'completed_at' => now(),
        ...$attributes,
    ]);

    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'type' => AllocationType::RegistrationFee,
        'currency_code' => 'BDT',
        'amount' => Money::fromDecimal('1000.00', Currency::BDT),
        'description' => 'Registration fee',
        'sort_order' => 1,
    ]);

    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'type' => AllocationType::PackageFee,
        'currency_code' => 'BDT',
        'amount' => Money::fromDecimal('5000.00', Currency::BDT),
        'description' => 'Growth package',
        'sort_order' => 2,
    ]);

    return $payment->load('allocations');
}

beforeEach(function () {
    $this->account = testBusinessAccount(AccountStatus::Active);
});

describe('what a receipt is issued for', function () {
    it('is issued for money that actually arrived', function () {
        $receipt = PaymentReceipt::forPayment(receiptPayment());

        expect($receipt)->not->toBeNull()
            ->and($receipt->amount['amount'])->toBe('6000.00')
            ->and($receipt->lines)->toHaveCount(2);
    });

    it('is not issued for a payment that never settled', function () {
        // There is no such thing as a receipt for money that did not arrive,
        // and issuing one would be the platform vouching for a payment it never
        // confirmed.
        $payment = receiptPayment([
            'status' => PaymentStatus::Initiated,
            'completed_at' => null,
        ]);

        expect(PaymentReceipt::forPayment($payment))->toBeNull();
    });

    it('is still issued for a payment that was later refunded', function () {
        // The money did arrive. The refund is shown beside that fact rather
        // than replacing it.
        $payment = receiptPayment();
        $payment->transitionTo(PaymentStatus::Refunded)->save();

        expect(PaymentReceipt::forPayment($payment->fresh()->load('allocations')))
            ->not->toBeNull();
    });
});

describe('the number', function () {
    it('is the same every time it is asked for', function () {
        /*
         * Derived from the payment's own reference, which is unique and never
         * changes — so the same payment produces the same number today, next
         * year, and after any restore. A receipt whose number moved would be
         * worse than useless to whoever filed it.
         */
        $payment = receiptPayment();

        $first = PaymentReceipt::forPayment($payment)->number;
        $second = PaymentReceipt::forPayment($payment->fresh()->load('allocations'))->number;

        expect($first)->toBe($second)
            ->and($first)->toContain($payment->reference);
    });

    it('differs between payments', function () {
        $one = PaymentReceipt::forPayment(receiptPayment());
        $two = PaymentReceipt::forPayment(receiptPayment(['gateway_reference' => 'val-2']));

        expect($one->number)->not->toBe($two->number);
    });
});

describe('what a receipt never carries', function () {
    it('shows the provider transaction and nothing else about the exchange', function () {
        /*
         * The gateway reference is here because a dispute needs it. A
         * signature, a raw payload or a credential would be a secret leaving
         * the platform in a document the account holder can forward to anybody
         * (§42).
         */
        $receipt = PaymentReceipt::forPayment(receiptPayment())->toArray();

        $serialised = json_encode($receipt);

        expect($receipt['gateway_reference'])->toBe('val-1')
            ->and($serialised)->not->toContain('verify_sign')
            ->and($serialised)->not->toContain('store_passwd')
            ->and($serialised)->not->toContain('signature');
    });

    it('shows a refund without the staff notes attached to it', function () {
        // The reason and the decision note are written by staff for staff
        // (§7.3). The amount and the date are the account holder's business.
        $payment = receiptPayment();

        RefundRequest::factory()->create([
            'payment_id' => $payment->id,
            'business_account_id' => $this->account->id,
            'status' => RefundStatus::Processed,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal('1000.00', Currency::BDT),
            'reason' => 'Customer complained about the onboarding call.',
            'decision_note' => 'Goodwill only. Do not repeat for this account.',
            'processed_at' => now(),
        ]);

        $receipt = PaymentReceipt::forPayment($payment);
        $serialised = json_encode($receipt->toArray());

        expect($receipt->refunds)->toHaveCount(1)
            ->and($receipt->refunds[0]['amount']['amount'])->toBe('1000.00')
            ->and($serialised)->not->toContain('Goodwill only')
            ->and($serialised)->not->toContain('onboarding call');
    });

    it('counts only refunds that actually went back', function () {
        // An approved refund nobody has sent is not money the account holder
        // has received, and a receipt claiming otherwise would be wrong in the
        // direction that matters.
        $payment = receiptPayment();

        RefundRequest::factory()->create([
            'payment_id' => $payment->id,
            'business_account_id' => $this->account->id,
            'status' => RefundStatus::Approved,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal('1000.00', Currency::BDT),
        ]);

        expect(PaymentReceipt::forPayment($payment)->refunds)->toBe([]);
    });
});

describe('currency', function () {
    it('shows what the provider settled when it differs from what was charged', function () {
        /*
         * Recording both is what stops a foreign settlement being silently
         * rewritten into the base ledger (D4) — and the payer is entitled to
         * see what their provider actually took.
         */
        $payment = receiptPayment([
            'settled_currency_code' => 'USD',
            'settled_amount' => Money::fromDecimal('50.00', Currency::USD),
        ]);

        expect(PaymentReceipt::forPayment($payment)->settled)
            ->toBe([
                'amount' => '50.00',
                'currency' => 'USD',
                'formatted' => '$50.00',
            ]);
    });

    it('says nothing when the settlement is the same money said twice', function () {
        $payment = receiptPayment([
            'settled_currency_code' => 'BDT',
            'settled_amount' => Money::fromDecimal('6000.00', Currency::BDT),
        ]);

        expect(PaymentReceipt::forPayment($payment)->settled)->toBeNull();
    });
});

describe('a sandbox payment says so', function () {
    it('marks a receipt taken in a gateway sandbox', function () {
        /*
         * A test payment must never produce a document that looks like proof of
         * real money — and the mode is read from what the payment recorded at
         * the time, not from today's settings.
         */
        $payment = receiptPayment(['gateway_mode' => 'sandbox']);

        expect(PaymentReceipt::forPayment($payment)->isSandbox)->toBeTrue();
    });

    it('does not mark a live one', function () {
        expect(PaymentReceipt::forPayment(receiptPayment())->isSandbox)->toBeFalse();
    });
});

describe('an account sees only its own', function () {
    it('lists this account\'s receipts', function () {
        receiptPayment();

        $this->actingAs($this->account->owner)
            ->get(route('subscription.receipts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/receipts')
                ->has('receipts.data', 1),
            );
    });

    it('shows one of this account\'s receipts', function () {
        $payment = receiptPayment();

        $this->actingAs($this->account->owner)
            ->get(route('subscription.receipts.show', $payment->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/receipt')
                ->where('receipt.payment_reference', $payment->reference),
            );
    });

    it('finds nothing for another account\'s payment', function () {
        /*
         * Scoped by construction, so a changed identifier finds nothing rather
         * than finding somebody else's receipt and being refused (§31.3). The
         * difference matters: one of those confirms the payment exists.
         */
        $other = testBusinessAccount(AccountStatus::Active);

        $payment = Payment::create([
            'business_account_id' => $other->id,
            'purpose' => PaymentPurpose::Activation,
            'status' => PaymentStatus::Paid,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal('9000.00', Currency::BDT),
            'gateway' => 'sslcommerz',
            'gateway_reference' => 'val-other',
            'completed_at' => now(),
        ]);

        $this->actingAs($this->account->owner)
            ->get(route('subscription.receipts.show', $payment->public_id))
            ->assertNotFound();
    });

    it('does not list another account\'s receipts', function () {
        $other = testBusinessAccount(AccountStatus::Active);

        Payment::create([
            'business_account_id' => $other->id,
            'purpose' => PaymentPurpose::Activation,
            'status' => PaymentStatus::Paid,
            'currency_code' => 'BDT',
            'amount' => Money::fromDecimal('9000.00', Currency::BDT),
            'gateway' => 'sslcommerz',
            'gateway_reference' => 'val-other-2',
            'completed_at' => now(),
        ]);

        $this->actingAs($this->account->owner)
            ->get(route('subscription.receipts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('receipts.data', 0))
            ->assertDontSee('9,000');
    });

    it('shows no receipt for a payment that never settled', function () {
        $payment = receiptPayment([
            'status' => PaymentStatus::Initiated,
            'completed_at' => null,
        ]);

        $this->actingAs($this->account->owner)
            ->get(route('subscription.receipts.show', $payment->public_id))
            ->assertNotFound();
    });
});
