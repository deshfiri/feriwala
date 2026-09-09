<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Support\Money\Money;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Turns a recorded payment into an invoice (§8.2, §9).
 *
 * Issued when the payment is **recorded**, not when it settles. An invoice is
 * what somebody is asked to pay; waiting for the money would mean an account
 * with nothing to show for a bill it has not paid yet — and §8.2 lists the
 * invoice beside the payment history rather than inside it.
 *
 * That is not the same as granting anything. Whether the money arrived is the
 * payment's business, read through {@see Invoice::isPaid()}, and nothing in the
 * subscription lifecycle reads an invoice at all.
 *
 * The lines are **copied**, including their labels. A payment can be retried,
 * fail, settle late or be reconciled against a gateway that reports something
 * else; the document already sent to a customer has to go on saying what it
 * said, and a fee renamed next year must not retitle a line on it.
 *
 * Idempotent at the index. `payment_id` is unique, so two concurrent requests
 * both try and the loser returns the row the winner created — an
 * application-level check would let both through.
 */
class IssueInvoice
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    public function handle(Payment $payment): Invoice
    {
        $existing = Invoice::query()->where('payment_id', $payment->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->transaction(fn () => $this->issue($payment));
        } catch (UniqueConstraintViolationException $exception) {
            $invoice = Invoice::query()->where('payment_id', $payment->id)->first();

            if ($invoice === null) {
                throw $exception;
            }

            return $invoice;
        }
    }

    protected function issue(Payment $payment): Invoice
    {
        $payment->loadMissing('allocations');

        $currency = $payment->amount_minor->currency;

        $invoice = Invoice::create([
            'business_account_id' => $payment->business_account_id,
            'payment_id' => $payment->id,
            'purpose' => $payment->purpose,
            'currency_code' => $currency->value,
            'subtotal_minor' => $this->subtotal($payment, $currency),
            'total_minor' => $payment->amount_minor,
            'issued_at' => now(),

            // Filled in below, once the row has an id to derive it from. The
            // column is not null, so a placeholder unique to this row goes in
            // first rather than an empty string every insert would collide on.
            'number' => 'PENDING-'.$payment->reference,
        ]);

        // Written straight to the connection: the model refuses updates on
        // purpose, and this is the one write that has to happen before the
        // invoice is a document anybody has seen.
        $number = Invoice::numberFor($invoice->id, $invoice->issued_at);

        Invoice::query()->whereKey($invoice->id)->update(['number' => $number]);
        $invoice->setAttribute('number', $number)->syncOriginal();

        foreach ($payment->allocations as $index => $allocation) {
            $invoice->lines()->create([
                'type' => $allocation->type->value,
                'label' => $allocation->description ?? $allocation->type->label(),
                'amount_minor' => $allocation->amount_minor,
                'currency_code' => $allocation->amount_minor->currency->value,
                'is_deduction' => $allocation->type->isDeduction(),
                'sort_order' => $allocation->sort_order ?? $index,
            ]);
        }

        return $invoice->load('lines');
    }

    /**
     * Fees before deductions, tax and the gateway charge.
     *
     * The same definition the quote uses, so an invoice and the checkout it
     * came from cannot disagree about what the subtotal means.
     */
    protected function subtotal(Payment $payment, mixed $currency): Money
    {
        $total = Money::zero($currency);

        foreach ($payment->allocations as $allocation) {
            if ($allocation->type->isDeduction()
                || $allocation->type === AllocationType::Tax
                || $allocation->type === AllocationType::GatewayCharge) {
                continue;
            }

            $total = $total->plus($allocation->amount_minor);
        }

        return $total;
    }
}
