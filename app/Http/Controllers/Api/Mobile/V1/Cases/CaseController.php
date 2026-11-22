<?php

namespace App\Http\Controllers\Api\Mobile\V1\Cases;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Cases\Actions\AttachDraftEvidence;
use App\Domain\Cases\Actions\StoreCaseEvidence;
use App\Domain\Cases\Exceptions\CaseEvidenceRefused;
use App\Domain\Cases\Queries\CaseEstimate;
use App\Domain\Cases\Queries\EvidencePayload;
use App\Domain\Cases\Queries\PartnerCasePayload;
use App\Domain\Order\Actions\CancelOrderReturn;
use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Queries\ReturnEligibility;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Http\Controllers\Api\Mobile\V1\Cases\Concerns\PresentsCasesToMobile;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A Partner's Returns & Exchanges (D35), as the web `cases.*` screens offer them.
 *
 * Self-scoped from the signed-in account: another Partner's order or case is a
 * 404. Reading needs the right to read orders; asking for a case, or withdrawing
 * one, needs the right to manage the store the order came through. Asking is one
 * request that makes one case, sent with a key, and every figure is the server's.
 */
class CaseController extends MobileController
{
    use PresentsCasesToMobile;
    use ResolvesBusinessAccount;

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

    /**
     * List returns and exchanges
     *
     * Every return and exchange this account has asked for, newest first, 20 to a
     * page. Filter with `type` (`return` or `exchange`) and `search` (case,
     * exchange or order reference).
     *
     * @response array{data: list<array<string, mixed>>, links: array<string, string|null>, meta: array<string, mixed>, filters: array{type: string|null, search: string|null}}
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $account = $this->orderReader($request);

        $kind = in_array($request->query('type'), ['return', 'exchange'], true) ? (string) $request->query('type') : null;
        $search = trim((string) $request->query('search', ''));
        $pattern = '%'.addcslashes(mb_substr($search, 0, 120), '%_\\').'%';

        $cases = OrderReturn::query()
            ->where('business_account_id', $account->id)
            ->where('workflow_version', '>=', 2)
            ->when($kind === 'exchange', fn ($query) => $query->where('case_type', ReturnCaseType::Exchange->value))
            ->when($kind === 'return', fn ($query) => $query->where('case_type', ReturnCaseType::Return->value))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('reference', 'ilike', $pattern)
                ->orWhere('exchange_number', 'ilike', $pattern)
                ->orWhereHas('order', fn ($order) => $order->where('reference', 'ilike', $pattern))))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (OrderReturn $case) => $this->payload->row($case));

        return JsonResource::collection($cases)->additional([
            'filters' => ['type' => $kind, 'search' => $search === '' ? null : $search],
        ]);
    }

    /**
     * Return or exchange detail
     *
     * One case in full: its lines, attachments, charges, refund and timeline.
     * `404` for another account's case.
     *
     * @response array{case: array<string, mixed>, items: list<array<string, mixed>>, evidence: list<array<string, mixed>>, legacy_evidence: list<array<string, mixed>>, financials: array<string, mixed>, refund: array<string, mixed>, timeline: mixed}
     */
    public function show(Request $request, string $case): JsonResponse
    {
        $account = $this->orderReader($request);

        return new JsonResponse($this->detail($this->own($account->id, $case)));
    }

    /**
     * What a return or exchange request needs
     *
     * For one delivered Store Order: what can still come back (`eligibility`), the
     * reasons, the replacement choices, the server's estimate for nothing yet,
     * unsent attachments, the upload limits, and whether this person may ask
     * (`can_request`). `404` for another account's order.
     *
     * @response array{order: array{id: string, reference: string}, eligibility: array<string, mixed>, reasons: list<array{value: string, label: string}>, replacements: list<array{sku: string, label: string}>, estimate: array<string, mixed>, drafts: list<array<string, mixed>>, evidence_limits: array<string, mixed>, can_request: bool}
     */
    public function create(Request $request, string $order): JsonResponse
    {
        $account = $this->businessAccountFor($request);
        $record = $this->storeOrderFor($account->id, $order);
        $actor = $this->person($request);

        $report = $this->eligibility->for($record);
        $website = Website::query()->find($record->website_id);

        return new JsonResponse([
            'order' => ['id' => $record->public_id, 'reference' => $record->reference],
            'eligibility' => $report,
            'reasons' => array_map(fn (ReturnReason $reason) => ['value' => $reason->value, 'label' => $reason->label()], ReturnReason::cases()),
            'replacements' => $website === null ? [] : $this->replacementChoices($website),
            'estimate' => $this->estimates->for($record, ReturnCaseType::Return, []),
            'drafts' => $this->drafts($this->evidence, $actor, $record),
            'evidence_limits' => $this->evidenceLimits($this->uploads->maxBytes(), $this->uploads->maxPerCase()),
            'can_request' => $report['eligible'] && $this->managesStoreOf($actor, $record),
        ]);
    }

    /**
     * Estimate a return or exchange
     *
     * The server's figures for what is about to be asked for: `type`, `lines`
     * (quantity by SKU) and, for an exchange, `replacements` (replacement SKU by
     * SKU). Nothing sent as an amount is read. `422` with `code` when more is
     * asked for than can come back.
     */
    public function estimate(Request $request, string $order): JsonResponse
    {
        $account = $this->businessAccountFor($request);
        $record = $this->storeOrderFor($account->id, $order);

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
                $this->replacements($validated['replacements'] ?? []),
            );
        } catch (ReturnRefused $refused) {
            return new JsonResponse(['message' => $this->refusal($refused), 'code' => $refused->errorCode], $refused->status);
        }

        return new JsonResponse($estimate);
    }

    /**
     * Ask for a return or exchange
     *
     * One request makes one case: `type`, `reason`, `description`, `lines`
     * (quantity by SKU), `replacements`, `evidence` (draft ids from
     * `POST /cases/evidence`), `confirmed` and an `idempotency_key` — sending the
     * same key again returns the same case. Answers `201` with the case as `show`
     * returns it. A Partner never says who is responsible and never sends an amount.
     *
     * @response 201 array{case: array<string, mixed>, items: list<array<string, mixed>>, evidence: list<array<string, mixed>>, legacy_evidence: list<array<string, mixed>>, financials: array<string, mixed>, refund: array<string, mixed>, timeline: mixed}
     */
    public function store(Request $request, string $order): JsonResponse
    {
        $account = $this->businessAccountFor($request);
        $record = $this->storeOrderFor($account->id, $order);
        $actor = $this->person($request);

        abort_unless($this->managesStoreOf($actor, $record), 403);

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
                    replacements: $this->replacements($validated['replacements'] ?? []),
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

        return new JsonResponse($this->detail($case->refresh()), 201);
    }

    /**
     * Withdraw a return or exchange
     *
     * Withdraws a case that is still open and returns it as `show` does. `422`
     * when it can no longer be withdrawn.
     *
     * @response array{case: array<string, mixed>, items: list<array<string, mixed>>, evidence: list<array<string, mixed>>, legacy_evidence: list<array<string, mixed>>, financials: array<string, mixed>, refund: array<string, mixed>, timeline: mixed}
     */
    public function cancel(Request $request, string $case): JsonResponse
    {
        $account = $this->businessAccountFor($request);
        $record = $this->own($account->id, $case);
        $actor = $this->person($request);

        abort_unless($this->managesStoreOf($actor, $record->order), 403);

        try {
            $this->cancellations->handle($record, OrderStatusChangeSource::Account, $actor, 'Withdrawn by the Partner.');
        } catch (ReturnRefused $refused) {
            throw ValidationException::withMessages(['case' => $this->refusal($refused)]);
        }

        return new JsonResponse($this->detail($record->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(OrderReturn $case): array
    {
        $detail = $this->payload->detail($case);
        $detail['evidence'] = $this->mobileEvidenceList($detail['evidence']);

        return $detail;
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

    /**
     * Replacement SKUs by SKU, with blanks dropped.
     *
     * @param  array<string, mixed>  $replacements
     * @return array<string, string>
     */
    protected function replacements(array $replacements): array
    {
        return array_filter($replacements, fn ($sku) => is_string($sku) && $sku !== '');
    }
}
