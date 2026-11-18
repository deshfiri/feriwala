<?php

namespace App\Domain\Billing\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\PaymentTaxLine;
use App\Domain\Order\Models\Order;

/**
 * An account's invoice as the web and mobile screens show it (§8.2, D19).
 *
 * One shape for both, so the two can never disagree about what a bill said.
 * Whether it was paid is read from its payment every time, never from a flag
 * of its own.
 */
class InvoicePayload
{
    /**
     * The row in a list.
     *
     * @return array{id: string, number: string, purpose: string, purpose_label: string, total: array<string, mixed>, issued_at: string, is_paid: bool, payment_reference: string|null, payment_status: string|null, paid_at: string|null}
     */
    public function summary(Invoice $invoice): array
    {
        return [
            'id' => $invoice->public_id,
            'number' => $invoice->number,
            'purpose' => $invoice->purpose->value,
            'purpose_label' => $invoice->purpose->label(),
            'total' => $invoice->total->jsonSerialize(),
            'issued_at' => $invoice->issued_at->toIso8601String(),

            // From the payment, not from a column here.
            'is_paid' => $invoice->isPaid(),
            'payment_reference' => $invoice->payment?->reference,
            'payment_status' => $invoice->payment?->status->label(),
            'paid_at' => $invoice->payment?->completed_at?->toIso8601String(),
        ];
    }

    /**
     * The whole invoice: its lines, its tax per rate, and the order it records
     * the sale of. Expects `lines`, `payment.taxLines` and `payment.payable`
     * loaded, and an invoice already scoped to `$account`.
     *
     * @return array<string, mixed>
     */
    public function detail(Invoice $invoice, BusinessAccount $account): array
    {
        $order = $invoice->payment?->payable;

        return array_merge($this->summary($invoice), [
            // The wholesale order this invoice records the sale of (P4-11).
            // Already scoped: the invoice is this account's, and so is its order.
            'order' => $order instanceof Order && $order->business_account_id === $account->id ? [
                'id' => $order->public_id,
                'reference' => $order->reference,
            ] : null,
            'subtotal' => $invoice->subtotal->jsonSerialize(),
            'lines' => $invoice->lines
                ->map(fn (InvoiceLine $line) => [
                    'type' => $line->type,
                    'label' => $line->label,
                    'amount' => $line->amount->jsonSerialize(),
                    'is_deduction' => $line->is_deduction,
                ])
                ->all(),

            /*
             * D19 requires a tax breakdown on the invoice: "VAT 15% on
             * 5,000 — 750", per rate, with the taxable base beside the tax.
             * Read from the payment's tax lines, which are themselves copies
             * taken at the moment of the charge — so this is one snapshot
             * rendered, not a second one that could drift from it.
             */
            'tax' => $this->taxBreakdown($invoice),
        ]);
    }

    /**
     * The tax charged, per rate, as it was on the day.
     *
     * @return array<int, array{label: string, rate: string, is_inclusive: bool, net: array<string, mixed>, tax: array<string, mixed>}>
     */
    protected function taxBreakdown(Invoice $invoice): array
    {
        $payment = $invoice->payment;

        if ($payment === null) {
            return [];
        }

        return $payment->taxLines
            ->map(fn (PaymentTaxLine $line) => [
                'label' => $line->label,
                'rate' => $line->formattedRate(),
                'is_inclusive' => $line->mode->isInclusive(),
                'net' => $line->taxable_amount->jsonSerialize(),
                'tax' => $line->tax_amount->jsonSerialize(),
            ])
            ->values()
            ->all();
    }
}
