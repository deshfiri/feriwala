<?php

namespace App\Http\Controllers\Api\Mobile\V1\Cases;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Cases\Actions\AttachDraftEvidence;
use App\Domain\Cases\Actions\StoreCaseEvidence;
use App\Domain\Cases\Exceptions\CaseEvidenceRefused;
use App\Domain\Cases\Queries\CaseFinancials;
use App\Domain\Cases\Queries\CaseTimeline;
use App\Domain\Cases\Queries\EvidencePayload;
use App\Domain\Cases\Queries\ProductImage;
use App\Domain\Damage\Actions\ConfirmDamageReceivedByPartner;
use App\Domain\Damage\Actions\ReportDamage;
use App\Domain\Damage\Actions\RequestDamageDelivery;
use App\Domain\Damage\Data\DamageReport;
use App\Domain\Damage\Enums\DamageCondition;
use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Damage\Queries\DamageDeliveryQuote;
use App\Domain\Damage\Queries\DamagePayload;
use App\Domain\Damage\Queries\DamageReportEligibility;
use App\Domain\Damage\Queries\MyDamages;
use App\Http\Controllers\Api\Mobile\V1\Cases\Concerns\PresentsCasesToMobile;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A Partner's My Damages (D35), as the web `damages.*` screens offer them.
 *
 * Self-scoped from the signed-in account: the list is the items the Partner bears
 * the loss for, and another Partner's damage — or one Banij bears — is a 404. A
 * delivery request carries only which saved address; the charge is the delivery
 * service's figure, recalculated on the server when it is confirmed.
 */
class DamageController extends MobileController
{
    use PresentsCasesToMobile;
    use ResolvesBusinessAccount;

    public const PER_PAGE = 20;

    public function __construct(
        protected MyDamages $list,
        protected DamagePayload $payload,
        protected CaseFinancials $financials,
        protected CaseTimeline $timeline,
        protected EvidencePayload $evidence,
        protected ProductImage $images,
        protected DamageDeliveryQuote $quotes,
        protected RequestDamageDelivery $deliveries,
        protected ConfirmDamageReceivedByPartner $receipts,
        protected DamageReportEligibility $eligibility,
        protected ReportDamage $reports,
        protected AttachDraftEvidence $attachments,
        protected StoreCaseEvidence $uploads,
        protected DatabaseManager $database,
    ) {}

    /**
     * List my damages
     *
     * The damaged items this account bears the loss for, 20 to a page.
     *
     * @response array{data: list<array<string, mixed>>, links: array<string, string|null>, meta: array<string, mixed>}
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $account = $this->orderReader($request);

        $damages = $this->list->paginate($account, self::PER_PAGE)
            ->withQueryString()
            ->through(fn (OrderDamage $damage) => $this->row($damage));

        return JsonResource::collection($damages);
    }

    /**
     * Damage detail
     *
     * One damaged item: its status, attachments, charges, timeline, whether a
     * delivery may be asked for (with the server's quote and the account's saved
     * addresses) and whether its receipt may be confirmed. `404` for another
     * account's damage.
     *
     * @response array{damage: array<string, mixed>, evidence: list<array<string, mixed>>, legacy_evidence: list<array<string, mixed>>, financials: array<string, mixed>, timeline: mixed, delivery: array{can_request: bool, quote: mixed, addresses: list<array{id: string, label: string, is_default: bool}>}, can_confirm_receipt: bool}
     */
    public function show(Request $request, string $damage): JsonResponse
    {
        $account = $this->orderReader($request);

        return new JsonResponse($this->detail($request, $account, $this->own($account, $damage)));
    }

    /**
     * What a damage report needs
     *
     * For one delivered Store Order: what can still be reported (`eligibility`),
     * the conditions, the minimum description length, unsent attachments, the
     * upload limits, and whether this person may report (`can_report`). `404` for
     * another account's order.
     *
     * @response array{order: array{id: string, reference: string}, eligibility: array<string, mixed>, conditions: list<array{value: string, label: string}>, minimum_description: int, drafts: list<array<string, mixed>>, evidence_limits: array<string, mixed>, can_report: bool}
     */
    public function create(Request $request, string $order): JsonResponse
    {
        $account = $this->orderReader($request);
        $record = $this->storeOrderFor($account->id, $order);
        $actor = $this->person($request);
        $report = $this->eligibility->for($record);

        return new JsonResponse([
            'order' => ['id' => $record->public_id, 'reference' => $record->reference],
            'eligibility' => $report,
            'conditions' => array_map(fn (DamageCondition $condition) => ['value' => $condition->value, 'label' => $condition->label()], DamageCondition::cases()),
            'minimum_description' => ReportDamage::MINIMUM_DESCRIPTION,
            'drafts' => $this->drafts($this->evidence, $actor, $record),
            'evidence_limits' => $this->evidenceLimits($this->uploads->maxBytes(), $this->uploads->maxPerCase()),
            'can_report' => $report['eligible'] && $this->managesStoreOf($actor, $record),
        ]);
    }

    /**
     * Report a damaged item
     *
     * One report, once: `item` (the order line id), `quantity`, `condition`,
     * `description`, `item_location` (`send_back` or `stays_with_customer`),
     * `evidence` (draft ids from `POST /cases/evidence`), `confirmed` and an
     * `idempotency_key` — sending the same key again returns the same damage.
     * Answers `201` with the damage as `show` returns it. The Partner never says
     * who is responsible; staff decide after seeing the goods.
     *
     * @response 201 array{damage: array<string, mixed>, evidence: list<array<string, mixed>>, legacy_evidence: list<array<string, mixed>>, financials: array<string, mixed>, timeline: mixed, delivery: array<string, mixed>, can_confirm_receipt: bool}
     */
    public function store(Request $request, string $order): JsonResponse
    {
        $account = $this->orderReader($request);
        $record = $this->storeOrderFor($account->id, $order);
        $actor = $this->person($request);

        abort_unless($this->managesStoreOf($actor, $record), 403);

        $validated = $request->validate([
            'item' => ['required', 'string', 'max:40'],
            'quantity' => ['required', 'integer', 'min:1'],
            'condition' => ['required', Rule::enum(DamageCondition::class)],
            'description' => ['required', 'string', 'min:'.ReportDamage::MINIMUM_DESCRIPTION, 'max:2000'],
            'item_location' => ['required', Rule::in(['send_back', 'stays_with_customer'])],
            'evidence' => ['nullable', 'array', 'max:'.$this->uploads->maxPerCase()],
            'evidence.*' => ['string', 'max:40'],
            'confirmed' => ['accepted'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        try {
            $damage = $this->database->transaction(function () use ($actor, $record, $validated) {
                $opened = $this->reports->fromDelivered($actor, $record, $validated['item'], new DamageReport(
                    quantity: (int) $validated['quantity'],
                    condition: DamageCondition::from($validated['condition']),
                    description: $validated['description'],
                    // An item that stays with the customer has no parcel, no receipt and no delivery.
                    parcelExpected: $validated['item_location'] === 'send_back',
                    idempotencyKey: 'account:'.$validated['idempotency_key'],
                ));

                // A retry of the same report finds it and attaches nothing more.
                if ($opened->wasRecentlyCreated) {
                    $this->attachments->handle($actor, $opened, $validated['evidence'] ?? []);
                }

                return $opened;
            });
        } catch (DamageRefused|CaseEvidenceRefused $refused) {
            throw ValidationException::withMessages(['quantity' => $this->refusal($refused)]);
        } catch (AuthorizationException) {
            abort(403);
        }

        return new JsonResponse($this->detail($request, $account, $damage->refresh()), 201);
    }

    /**
     * Ask for delivery of a damaged item
     *
     * To one of the account's own saved addresses (`address`, from
     * `delivery.addresses`), with `confirmed`. The charge comes off the wallet at
     * the server's figure; no amount is sent. Returns the damage as `show` does.
     *
     * @response array{damage: array<string, mixed>, evidence: list<array<string, mixed>>, legacy_evidence: list<array<string, mixed>>, financials: array<string, mixed>, timeline: mixed, delivery: array<string, mixed>, can_confirm_receipt: bool}
     */
    public function requestDelivery(Request $request, string $damage): JsonResponse
    {
        $account = $this->orderReader($request);
        $record = $this->own($account, $damage);

        $validated = $request->validate([
            'address' => ['required', 'string', 'max:40'],
            'confirmed' => ['accepted'],
        ]);

        $actor = $this->person($request);

        $this->attempt(fn () => $this->deliveries->handle($actor, $record, $validated['address']), 'address');

        return new JsonResponse($this->detail($request, $account, $record->refresh()));
    }

    /**
     * Confirm a damaged item arrived
     *
     * Once a delivered item has reached the Partner. Returns the damage as `show` does.
     *
     * @response array{damage: array<string, mixed>, evidence: list<array<string, mixed>>, legacy_evidence: list<array<string, mixed>>, financials: array<string, mixed>, timeline: mixed, delivery: array<string, mixed>, can_confirm_receipt: bool}
     */
    public function confirmReceipt(Request $request, string $damage): JsonResponse
    {
        $account = $this->orderReader($request);
        $record = $this->own($account, $damage);
        $actor = $this->person($request);

        $this->attempt(fn () => $this->receipts->handle($actor, $record), 'case');

        return new JsonResponse($this->detail($request, $account, $record->refresh()));
    }

    /**
     * @param  callable(): mixed  $step
     */
    protected function attempt(callable $step, string $field): void
    {
        try {
            $step();
        } catch (DamageRefused|CaseEvidenceRefused $refused) {
            throw ValidationException::withMessages([$field => $this->refusal($refused)]);
        } catch (AuthorizationException) {
            abort(403);
        }
    }

    protected function own(BusinessAccount $account, string $publicId): OrderDamage
    {
        $record = $this->list->find($account, $publicId);

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(Request $request, BusinessAccount $account, OrderDamage $record): array
    {
        $record->loadMissing(['order:id,public_id,reference', 'orderItem.product', 'evidenceFiles.file', 'charges', 'dispatch.courierProvider']);

        $payload = $this->payload->forPartner($record);
        $mayRequest = Gate::forUser($this->person($request))->allows('requestDelivery', $record);

        return [
            'damage' => [
                ...$payload,
                'id' => $record->public_id,
                'image' => $this->images->for($record->orderItem),
                'order_id' => $record->order->public_id,
                'description' => $record->description,
                'remains_with_customer' => ! $record->in_custody && ! $record->parcel_expected,
                'tracking' => $record->dispatch === null ? null : [
                    'number' => $record->dispatch->tracking_number,
                    'courier' => $record->dispatch->courierProvider?->name,
                ],
            ],
            'evidence' => $this->mobileEvidenceList($record->evidenceFiles
                ->filter(fn ($file) => ! $file->isDraft())
                ->map(fn ($file) => $this->evidence->for($file))
                ->values()
                ->all()),
            'legacy_evidence' => $this->evidence->legacy($record->evidence),
            'financials' => $this->financials->forDamage($record),
            'timeline' => $this->timeline->forDamage($record, false),
            'delivery' => [
                'can_request' => $mayRequest && $payload['can_request_delivery'],
                'quote' => $mayRequest && $payload['can_request_delivery'] ? $this->quotes->for($record) : null,
                'addresses' => $this->addresses($account),
            ],
            'can_confirm_receipt' => $mayRequest && $record->status->value === 'dispatched',
        ];
    }

    /**
     * One row of the list.
     *
     * @return array<string, mixed>
     */
    protected function row(OrderDamage $damage): array
    {
        $payload = $this->payload->forPartner($damage);

        return [
            'id' => $damage->public_id,
            'reference' => $payload['reference'],
            'order_number' => $payload['order_number'],
            'product' => $payload['product'],
            'image' => $this->images->for($damage->orderItem),
            'quantity' => $payload['quantity'],
            'cause' => $payload['cause'],
            'cause_label' => $payload['cause_label'],
            'approved_loss' => $payload['approved_loss'],
            'responsibility' => $payload['responsibility'],
            'responsibility_label' => $payload['responsibility_label'],
            'storage_state' => $payload['storage_state'],
            'delivery_state' => $payload['delivery_request']['state'],
            'status' => $payload['status'],
            'status_label' => $payload['status_label'],
            'updated_at' => $damage->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The Partner's own active saved addresses.
     *
     * @return list<array{id: string, label: string, is_default: bool}>
     */
    protected function addresses(BusinessAccount $account): array
    {
        return array_values(SharedAddress::query()
            ->where('owner_type', AddressOwnerType::BusinessAccount->value)
            ->where('owner_id', $account->id)
            ->where('status', AddressStatus::Active->value)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (SharedAddress $address) => [
                'id' => $address->public_id,
                'label' => trim($address->contact_name.' · '.$address->detailed_address.($address->postcode ? ' '.$address->postcode : '')),
                'is_default' => $address->is_default,
            ])
            ->all());
    }
}
