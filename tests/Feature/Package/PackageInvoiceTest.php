<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\RecordPaymentFromQuote;
use App\Domain\Billing\Data\ActivationQuote;
use App\Domain\Billing\Data\QuoteLine;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Invoice;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Package invoices and payment history (P1-42, §8.2, §9).
 *
 * An invoice is a document somebody has a copy of. Changing it after the fact
 * means the copy and the record disagree, and the one that is wrong is ours.
 */

function invoiceTestQuote(): ActivationQuote
{
    return new ActivationQuote([
        new QuoteLine(AllocationType::RenewalFee, Money::of(400000, Currency::BDT), 'Growth package renewal'),
        new QuoteLine(AllocationType::Discount, Money::of(50000, Currency::BDT), 'Loyalty credit'),
        new QuoteLine(AllocationType::Tax, Money::of(52500, Currency::BDT), 'VAT (15%)'),
    ], Currency::BDT);
}

beforeEach(function () {
    $this->account = BusinessAccount::factory()->create(['status' => AccountStatus::Active]);
});

function invoiceTestPayment(BusinessAccount $account, ?string $key = null)
{
    return app(RecordPaymentFromQuote::class)->handle(
        account: $account,
        quote: invoiceTestQuote(),
        purpose: PaymentPurpose::PackageRenewal,
        idempotencyKey: $key,
    );
}

describe('issuing', function () {
    it('comes with the payment rather than after it', function () {
        // A payment recorded without the document explaining it would leave an
        // account with a figure to pay and nothing itemising it.
        $payment = invoiceTestPayment($this->account);

        $invoice = Invoice::query()->where('payment_id', $payment->id)->firstOrFail();

        expect($invoice->total_minor->minorUnits)->toBe($payment->amount_minor->minorUnits)
            ->and($invoice->purpose)->toBe(PaymentPurpose::PackageRenewal)
            ->and($invoice->number)->toStartWith('INV-');
    });

    it('copies every line, label and all', function () {
        /*
         * §9 wants the components stored separately, and a fee renamed next
         * year must not retitle a line on an invoice somebody already has.
         */
        $payment = invoiceTestPayment($this->account);
        $invoice = Invoice::query()->with('lines')->firstOrFail();

        $labels = $invoice->lines->pluck('label')->all();

        expect($invoice->lines)->toHaveCount(3)
            ->and($labels)->toContain('Growth package renewal')
            ->and($labels)->toContain('Loyalty credit')
            // The deduction is marked, so nothing has to know which types
            // happen to be deductions to add the column up.
            ->and($invoice->lines->firstWhere('type', 'discount')?->is_deduction)->toBeTrue()
            ->and($payment->allocations)->toHaveCount(3);
    });

    it('totals fees before deductions, tax and the gateway charge', function () {
        $payment = invoiceTestPayment($this->account);
        $invoice = Invoice::query()->firstOrFail();

        expect($invoice->subtotal_minor->minorUnits)->toBe(400000)
            ->and($invoice->total_minor->minorUnits)->toBe($payment->amount_minor->minorUnits);
    });

    it('issues one invoice per payment, however many times it is asked', function () {
        // Idempotent at the index: `payment_id` is unique, so two concurrent
        // requests both try and the loser returns the winner's row.
        $payment = invoiceTestPayment($this->account);

        $first = app(IssueInvoice::class)->handle($payment);
        $second = app(IssueInvoice::class)->handle($payment);

        expect($second->id)->toBe($first->id)
            ->and(Invoice::query()->count())->toBe(1);
    });

    it('gives every invoice a number of its own', function () {
        invoiceTestPayment($this->account, 'renewal:one');
        invoiceTestPayment($this->account, 'renewal:two');

        $numbers = Invoice::query()->pluck('number');

        expect($numbers)->toHaveCount(2)
            ->and($numbers->unique())->toHaveCount(2);
    });
});

describe('immutability', function () {
    it('refuses to be edited', function () {
        // The same rule the audit log and the ledger follow: a correction is a
        // new document, not an edit (§36.1).
        invoiceTestPayment($this->account);
        $invoice = Invoice::query()->firstOrFail();

        expect(fn () => $invoice->forceFill(['total_minor' => 1])->save())
            ->toThrow(RuntimeException::class);
    });

    it('refuses to be deleted', function () {
        invoiceTestPayment($this->account);
        $invoice = Invoice::query()->firstOrFail();

        expect(fn () => $invoice->delete())->toThrow(RuntimeException::class);
    });

    it('refuses to let a line be edited or deleted', function () {
        invoiceTestPayment($this->account);
        $line = Invoice::query()->with('lines')->firstOrFail()->lines->first();

        expect(fn () => $line->forceFill(['amount_minor' => 1])->save())
            ->toThrow(RuntimeException::class)
            ->and(fn () => $line->delete())->toThrow(RuntimeException::class);
    });
});

describe('whether it has been paid', function () {
    it('reads the payment rather than a flag of its own', function () {
        /*
         * A second answer to a question the payment already answers is a second
         * answer that drifts the first time a settlement arrives late — and
         * §8.2's "a pending invoice grants nothing" depends on there being one.
         */
        $payment = invoiceTestPayment($this->account);
        $invoice = Invoice::query()->with('payment')->firstOrFail();

        expect($invoice->isPaid())->toBeFalse();

        $payment->transitionTo(PaymentStatus::Initiated);
        $payment->transitionTo(PaymentStatus::Paid);
        $payment->forceFill(['completed_at' => now()])->save();

        expect($invoice->load('payment')->isPaid())->toBeTrue();
    });
});

describe('the account\'s own invoices', function () {
    it('lists them newest first with whether each is paid', function () {
        invoiceTestPayment($this->account, 'renewal:one');

        $this->actingAs($this->account->owner)
            ->get(route('subscription.invoices.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/invoices')
                ->has('invoices.data', 1)
                ->where('invoices.data.0.is_paid', false)
                ->has('invoices.data.0.number'),
            );
    });

    it('shows one in full, itemised', function () {
        invoiceTestPayment($this->account, 'renewal:one');
        $invoice = Invoice::query()->firstOrFail();

        $this->actingAs($this->account->owner)
            ->get(route('subscription.invoices.show', $invoice->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/invoice')
                ->has('invoice.lines', 3)
                ->where('invoice.subtotal.minor_units', 400000),
            );
    });

    it('cannot reach an invoice belonging to somebody else', function () {
        // §31.3 as a query concern: found through the caller's own account, so
        // a changed identifier finds nothing rather than finding a stranger's
        // bill and being refused.
        $stranger = BusinessAccount::factory()->create(['status' => AccountStatus::Active]);
        invoiceTestPayment($stranger, 'renewal:theirs');

        $theirs = Invoice::query()->firstOrFail();

        $this->actingAs($this->account->owner)
            ->get(route('subscription.invoices.show', $theirs->public_id))
            ->assertNotFound();
    });

    it('is closed to somebody with no account', function () {
        $stranger = User::factory()->staff()->create();

        $this->actingAs($stranger)
            ->get(route('subscription.invoices.index'))
            ->assertForbidden();
    });
});
