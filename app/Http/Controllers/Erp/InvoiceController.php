<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Queries\InvoicePayload;
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
 * The shape is {@see InvoicePayload}'s, shared with the mobile API.
 */
class InvoiceController extends Controller
{
    use ResolvesBusinessAccount;

    /** How many invoices the list shows before paging. */
    public const PER_PAGE = 20;

    public function __construct(
        protected InvoicePayload $payload,
    ) {}

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
                    ->map(fn (Invoice $invoice) => $this->payload->summary($invoice))
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

        return Inertia::render('settings/invoice', [
            'invoice' => $this->payload->detail($record, $account),
        ]);
    }
}
