<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Billing\Data\PaymentReceipt;
use App\Domain\Billing\Models\Payment;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An account's own payment receipts (§26.4).
 *
 * **Self-scoped by construction** (§31.3). Receipts are reached through the
 * signed-in person's account relation, never by looking a payment up globally
 * and checking afterwards — so a changed identifier finds nothing rather than
 * finding somebody else's receipt and being refused. The difference matters:
 * one of those tells an attacker the payment exists.
 *
 * Only settled payments appear. There is no such thing as a receipt for money
 * that did not arrive, and the query says so rather than the page hiding it.
 */
class ReceiptController extends Controller
{
    use ResolvesBusinessAccount;

    /** How many receipts the list shows before paging. */
    public const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $account = $this->businessAccountFor($request);

        $payments = Payment::query()
            ->where('business_account_id', $account->id)

            // Money that actually arrived, which is the only thing a receipt
            // can be issued for.
            ->whereNotNull('completed_at')
            ->with('allocations')
            ->orderByDesc('completed_at')
            // A stable tie-break: several payments can complete in the same
            // second, and without this their order changes between page loads.
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('settings/receipts', [
            'receipts' => [
                'data' => $payments->getCollection()
                    ->map(fn (Payment $payment) => $this->summary($payment))
                    ->filter()
                    ->values()
                    ->all(),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    public function show(Request $request, string $payment): Response
    {
        $account = $this->businessAccountFor($request);

        $record = Payment::query()
            ->where('business_account_id', $account->id)
            ->where('public_id', $payment)
            ->with('allocations')
            ->firstOrFail();

        $receipt = PaymentReceipt::forPayment($record);

        // Found, but not something a receipt can be issued for.
        abort_if($receipt === null, 404);

        return Inertia::render('settings/receipt', [
            'receipt' => [...$receipt->toArray(), 'id' => $record->public_id],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function summary(Payment $payment): ?array
    {
        $receipt = PaymentReceipt::forPayment($payment);

        if ($receipt === null) {
            return null;
        }

        return [
            'id' => $payment->public_id,
            'number' => $receipt->number,
            'purpose' => $receipt->purpose,
            'amount' => $receipt->amount,
            'paid_at' => $receipt->paidAt->toIso8601String(),
            'is_sandbox' => $receipt->isSandbox,
            'refunded_total' => $receipt->refundedTotal,
        ];
    }
}
