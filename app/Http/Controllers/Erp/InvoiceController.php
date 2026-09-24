<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\PaymentTaxLine;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An account's own invoices and payment history (§8.2).
 *
 * Self-scoped by construction: invoices are reached through the signed-in
 * person's account relation, never by looking an id up globally and checking
 * afterwards — so a changed identifier finds nothing rather than finding
 * somebody else's bill and being refused (§31.3).
 *
 * Whether an invoice has been paid is read from its payment, every time. An
 * invoice carrying its own paid flag would be a second answer to a question the
 * payment already answers.
 */
class InvoiceController extends Controller
{
    use ResolvesBusinessAccount;

    /** How many invoices the list shows before paging. */
    public const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $account = $this->businessAccountFor($request);

        $invoices = Invoice::query()
            ->where('business_account_id', $account->id)
            ->with('payment')
            ->orderByDesc('issued_at')
            // A stable tie-break: several invoices can be issued in the same
            // second, and without this their order changes between page loads.
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('settings/invoices', [
            'invoices' => [
                'data' => $invoices->getCollection()
                    ->map(fn (Invoice $invoice) => $this->summary($invoice))
                    ->all(),
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    public function show(Request $request, string $invoice): Response
    {
        $account = $this->businessAccountFor($request);

        $record = Invoice::query()
            ->where('business_account_id', $account->id)
            ->where('public_id', $invoice)
            ->with(['lines', 'payment.taxLines', 'payment.payable'])
            ->firstOrFail();

        $order = $record->payment?->payable;

        return Inertia::render('settings/invoice', [
            'invoice' => array_merge($this->summary($record), [
                // The wholesale order this invoice records the sale of (P4-11).
                // Already scoped: the invoice is this account's, and so is its order.
                'order' => $order instanceof Order && $order->business_account_id === $account->id ? [
                    'id' => $order->public_id,
                    'reference' => $order->reference,
                ] : null,
                'subtotal' => $record->subtotal->jsonSerialize(),
                'lines' => $record->lines
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
                 * Read from the payment's tax lines, which are themselves
                 * copies taken at the moment of the charge — so this is one
                 * snapshot rendered, not a second one that could drift from it.
                 */
                'tax' => $this->taxBreakdown($record),
            ]),
        ]);
    }

    /**
     * The tax charged, per rate, as it was on the day.
     *
     * @return array<int, array<string, mixed>>
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
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Invoice $invoice): array
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
}
