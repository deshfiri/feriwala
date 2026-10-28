<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Cases\Actions\AttachDraftEvidence;
use App\Domain\Cases\Actions\StoreCaseEvidence;
use App\Domain\Cases\Exceptions\CaseEvidenceRefused;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Cases\Queries\CaseEstimate;
use App\Domain\Cases\Queries\EvidencePayload;
use App\Domain\Cases\Queries\PartnerCasePayload;
use App\Domain\Order\Actions\CancelOrderReturn;
use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Queries\ReturnEligibility;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Http\Controllers\Concerns\RunsCaseActions;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Partner's Returns & Exchanges (D35): asking for one from an order, and
 * following every one asked for.
 *
 * **Self-scoped from the signed-in account, never from a request.** Every query
 * starts from the Partner's own account, so another Partner's order or case is a
 * 404 whatever identifier is tried. Reading cases needs the account's right to
 * read its orders; asking for one, or withdrawing it, needs the right to manage
 * the store the order came through — the same bar as every other store action.
 *
 * Asking is **one request that makes one case**: the type, the lines and
 * quantities, the reason, a description and any attachments, sent with a key, so
 * a double click is the same request once. A Partner never says who is
 * responsible; the case begins as theirs and only staff decide otherwise. The
 * estimate shown beforehand is the server's, and the charge actually made is
 * decided after the goods are inspected.
 */
class CaseController extends Controller
{
    use ResolvesBusinessAccount;
    use RunsCaseActions;

    public const PER_PAGE = 20;

    public function __construct(
        protected PartnerCasePayload $payload,
        protected ReturnEligibility $eligibility,
        protected CaseEstimate $estimates,
        protected RequestOrderReturn $requests,
        protected CancelOrderReturn $cancellations,
        protected AttachDraftEvidence $attachments,
        protected EvidencePayload $evidence,
        protected StoreCaseEvidence $uploads,
        protected DatabaseManager $database,
    ) {}

    public function index(Request $request): Response
    {
        $account = $this->reader($request);

        $kind = in_array($request->query('type'), ['return', 'exchange'], true) ? (string) $request->query('type') : null;
        $search = trim((string) $request->query('search', ''));

        $cases = OrderReturn::query()
            ->where('business_account_id', $account->id)
            ->where('workflow_version', '>=', 2)
            ->when($kind === 'exchange', fn ($query) => $query->where('case_type', ReturnCaseType::Exchange->value))
            ->when($kind === 'return', fn ($query) => $query->where('case_type', ReturnCaseType::Return->value))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('reference', 'ilike', '%'.addcslashes(mb_substr($search, 0, 120), '%_\\').'%')
                ->orWhere('exchange_number', 'ilike', '%'.addcslashes(mb_substr($search, 0, 120), '%_\\').'%')
                ->orWhereHas('order', fn ($order) => $order->where('reference', 'ilike', '%'.addcslashes(mb_substr($search, 0, 120), '%_\\').'%'))))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (OrderReturn $case) => $this->payload->row($case));

        return Inertia::render('cases/index', [
            'cases' => $cases,
            'filters' => ['type' => $kind, 'search' => $search === '' ? null : $search],
        ]);
    }

    public function show(Request $request, string $case): Response
    {
        $account = $this->reader($request);

        return Inertia::render('cases/show', $this->payload->detail($this->own($account->id, $case)));
    }

    /**
     * The form for asking for a return or an exchange on one order.
     */
    public function create(Request $request, string $order): Response
    {
        $account = $this->businessAccountFor($request);
        $record = $this->orderFor($request, $account->id, $order);

        $report = $this->eligibility->for($record);
        $website = Website::query()->find($record->website_id);

        return Inertia::render('cases/create', [
            'order' => [
                'id' => $record->public_id,
                'reference' => $record->reference,
                'url' => route('store-orders.show', $record->public_id),
            ],
            'eligibility' => $report,
            'reasons' => array_map(fn (ReturnReason $reason) => ['value' => $reason->value, 'label' => $reason->label()], ReturnReason::cases()),
            'replacements' => $website === null ? [] : $this->replacementChoices($website),
            'estimate' => $this->estimates->for($record, ReturnCaseType::Return, []),
            // Attachments from an earlier, unsent attempt on this order.
            'drafts' => $this->drafts($this->person($request), $record),
            'evidence_limits' => [
                'max_bytes' => $this->uploads->maxBytes(),
                'max_per_case' => $this->uploads->maxPerCase(),
                'mime_types' => array_keys((array) config('case_evidence.mime_types')),
            ],
            'can_request' => $report['eligible'] && $website !== null && Gate::forUser($this->person($request))->allows('manage', $website),
        ]);
    }

    /**
     * The server's estimate for what is about to be asked for.
     */
    public function estimate(Request $request, string $order): JsonResponse
    {
        $account = $this->businessAccountFor($request);
        $record = $this->orderFor($request, $account->id, $order);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ReturnCaseType::class)],
            'lines' => ['present', 'array'],
            'lines.*' => ['integer', 'min:0'],
            'replacements' => ['nullable', 'array'],
            'replacements.*' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $estimate = $this->estimates->for(
                $record,
                ReturnCaseType::from($validated['type']),
                $this->quantities($validated['lines']),
                array_filter($validated['replacements'] ?? [], fn ($sku) => is_string($sku) && $sku !== ''),
            );
        } catch (ReturnRefused $refused) {
            return response()->json(['message' => $this->refusal($refused), 'code' => $refused->errorCode], $refused->status);
        }

        return response()->json($estimate);
    }

    public function store(Request $request, string $order): RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $record = $this->orderFor($request, $account->id, $order);
        $actor = $this->person($request);

        $website = Website::query()->find($record->website_id);

        abort_if($website === null || ! Gate::forUser($actor)->allows('manage', $website), 403);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ReturnCaseType::class)],
            'reason' => ['required', Rule::enum(ReturnReason::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array'],
            'lines.*' => ['integer', 'min:0'],
            'replacements' => ['nullable', 'array'],
            'replacements.*' => ['nullable', 'string', 'max:64'],
            'evidence' => ['nullable', 'array', 'max:'.$this->uploads->maxPerCase()],
            'evidence.*' => ['string', 'max:40'],
            'confirmed' => ['accepted'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $lines = [];

        foreach ($this->quantities($validated['lines']) as $sku => $quantity) {
            $lines[] = ['sku' => $sku, 'quantity' => $quantity];
        }

        try {
            $case = $this->database->transaction(function () use ($record, $validated, $lines, $actor) {
                [$opened, $created] = $this->requests->handle($record, new ReturnSubmission(
                    reason: ReturnReason::from($validated['reason']),
                    lines: $lines,
                    customerNote: $validated['description'] ?? null,
                    idempotencyKey: 'account:'.$validated['idempotency_key'],
                    caseType: ReturnCaseType::from($validated['type']),
                    replacements: array_filter($validated['replacements'] ?? [], fn ($sku) => is_string($sku) && $sku !== ''),
                ), OrderStatusChangeSource::Account, $actor);

                // A retry of the same request finds the case and attaches nothing more.
                if ($created) {
                    $this->attachments->handle($actor, $opened, $validated['evidence'] ?? []);
                }

                return $opened;
            });
        } catch (ReturnRefused|CaseEvidenceRefused $refused) {
            throw ValidationException::withMessages(['lines' => $this->refusal($refused)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('cases.partner.flash.requested')]);

        return to_route('cases.show', $case->public_id);
    }

    public function cancel(Request $request, string $case): RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $record = $this->own($account->id, $case);
        $actor = $this->person($request);

        $website = Website::query()->find($record->website_id);

        abort_if($website === null || ! Gate::forUser($actor)->allows('manage', $website), 403);

        try {
            $this->cancellations->handle($record, OrderStatusChangeSource::Account, $actor, 'Withdrawn by the Partner.');
        } catch (ReturnRefused $refused) {
            throw ValidationException::withMessages(['case' => $this->refusal($refused)]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('cases.partner.flash.withdrawn')]);

        return back();
    }

    /**
     * The Partner's own unsent attachments for an order, to show again if the form is reopened.
     *
     * @return list<array<string, mixed>>
     */
    protected function drafts(User $actor, Order $order): array
    {
        return array_values(CaseEvidence::query()
            ->where('uploader_id', $actor->id)
            ->where('order_id', $order->id)
            ->where('status', CaseEvidence::DRAFT)
            ->with('file')
            ->get()
            ->map(fn (CaseEvidence $file) => $this->evidence->for($file))
            ->all());
    }

    /**
     * The products a store sells, for choosing a replacement that is not the same one.
     *
     * @return list<array{sku: string, label: string}>
     */
    protected function replacementChoices(Website $website): array
    {
        return array_values(WebsiteProduct::query()
            ->where('website_id', $website->id)
            ->where('status', WebsiteProductStatus::Published->value)
            ->whereHas('product')
            ->with('product:id,name,sku')
            ->limit(100)
            ->get()
            ->map(fn (WebsiteProduct $selection) => ['sku' => $selection->product->sku, 'label' => $selection->product->name])
            ->sortBy('label')
            ->values()
            ->all());
    }

    /**
     * Quantities by SKU, with nothing asked for dropped.
     *
     * @param  array<string, mixed>  $lines
     * @return array<string, int>
     */
    protected function quantities(array $lines): array
    {
        $quantities = [];

        foreach ($lines as $sku => $quantity) {
            if ((int) $quantity > 0) {
                $quantities[(string) $sku] = (int) $quantity;
            }
        }

        return $quantities;
    }

    protected function own(int $accountId, string $publicId): OrderReturn
    {
        /** @var OrderReturn|null $case */
        $case = OrderReturn::query()
            ->where('business_account_id', $accountId)
            ->where('workflow_version', '>=', 2)
            ->where('public_id', $publicId)
            ->first();

        abort_if($case === null, 404);

        return $case;
    }

    /**
     * An order of this account, from one of its own stores — a 404 otherwise.
     */
    protected function orderFor(Request $request, int $accountId, string $publicId): Order
    {
        /** @var Order|null $order */
        $order = Order::query()
            ->where('public_id', $publicId)
            ->where('business_account_id', $accountId)
            ->whereIn('source', [OrderSource::Website->value, OrderSource::Exchange->value])
            ->first();

        abort_if($order === null, 404);

        return $order;
    }

    /**
     * The account, for someone in it who may read its orders.
     */
    protected function reader(Request $request): BusinessAccount
    {
        $account = $this->businessAccountFor($request);
        $user = $this->person($request);

        abort_unless($user->accountRole()?->hasPermission(AccountPermission::ViewOrders) ?? false, 403);

        return $account;
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
