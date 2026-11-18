<?php

namespace App\Http\Controllers\Api\Mobile\V1\Invoices;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Queries\InvoicePayload;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The business's own invoices (§8.2), the same as Settings → Invoices on the web.
 *
 * Every query starts from the signed-in person's account, so another business's
 * invoice is simply not found. Open before activation too: an account still
 * paying for its package needs to read what it was billed.
 */
class InvoiceController extends MobileController
{
    use ResolvesBusinessAccount;

    public const PER_PAGE = 20;

    public function __construct(
        protected InvoicePayload $payload,
    ) {}

    /**
     * List invoices
     *
     * Newest first, 20 to a page (`?page=2` for the next). `is_paid` and `paid_at` come from
     * the invoice's payment. Money is `{amount, currency, formatted}` in flat Taka.
     *
     * @response array{data: list<array{id: string, number: string, purpose: string, purpose_label: string, total: array<string, mixed>, issued_at: string, is_paid: bool, payment_reference: string|null, payment_status: string|null, paid_at: string|null}>, links: array<string, string|null>, meta: array<string, mixed>}
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $account = $this->businessAccountFor($request);

        $invoices = Invoice::query()
            ->where('business_account_id', $account->id)
            ->with('payment')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->through(fn (Invoice $invoice) => $this->payload->summary($invoice));

        return JsonResource::collection($invoices);
    }

    /**
     * Show an invoice
     *
     * One invoice by its `id` from the list: the summary fields, plus `subtotal`, `lines`
     * (each with `is_deduction` for a discount), `tax` per rate with its taxable `net`, and
     * the wholesale `order` it records the sale of, if any. `404` for an invoice that is not
     * this account's.
     *
     * @response array{data: array<string, mixed>}
     */
    public function show(Request $request, string $invoice): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        $record = Invoice::query()
            ->where('business_account_id', $account->id)
            ->where('public_id', $invoice)
            ->with(['lines', 'payment.taxLines', 'payment.payable'])
            ->firstOrFail();

        return response()->json(['data' => $this->payload->detail($record, $account)]);
    }
}
