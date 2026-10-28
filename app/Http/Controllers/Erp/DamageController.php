<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Cases\Actions\AttachDraftEvidence;
use App\Domain\Cases\Actions\StoreCaseEvidence;
use App\Domain\Cases\Exceptions\CaseEvidenceRefused;
use App\Domain\Cases\Models\CaseEvidence;
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
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;
use App\Domain\Website\Models\Website;
use App\Http\Controllers\Concerns\RunsCaseActions;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `My Products > My Damages` (D35): the Partner's own damaged items, one by one.
 *
 * **Self-scoped from the signed-in account, never from a request.** The list is
 * the items the Partner bears the loss for, filtered by their own account in the
 * query; another Partner's damage is a 404 whatever identifier is tried. A
 * damage Banij bears is not here at all.
 *
 * A Partner may ask for an item to be delivered to one of their own saved
 * addresses and pays the delivery from their wallet. The charge is the delivery
 * service's figure, shown before they confirm and recalculated on the server when
 * they do: the request carries only which address, never an amount.
 */
class DamageController extends Controller
{
    use ResolvesBusinessAccount;
    use RunsCaseActions;

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

    public function index(Request $request): Response
    {
        $account = $this->reader($request);

        $damages = $this->list->paginate($account, self::PER_PAGE)
            ->withQueryString()
            ->through(fn (OrderDamage $damage) => $this->row($damage));

        return Inertia::render('damages/index', ['damages' => $damages]);
    }

    public function show(Request $request, string $damage): Response
    {
        $account = $this->reader($request);
        $record = $this->list->find($account, $damage);

        abort_if($record === null, 404);

        $record->loadMissing(['order:id,public_id,reference', 'orderItem.product', 'evidenceFiles.file', 'charges', 'dispatch.courierProvider']);

        $payload = $this->payload->forPartner($record);
        $mayRequest = Gate::forUser($request->user())->allows('requestDelivery', $record);

        return Inertia::render('damages/show', [
            'damage' => [
                ...$payload,
                'id' => $record->public_id,
                'image' => $this->images->for($record->orderItem),
                'order_url' => route('store-orders.show', $record->order->public_id),
                'description' => $record->description,
                'remains_with_customer' => ! $record->in_custody && ! $record->parcel_expected,
                'tracking' => $record->dispatch === null ? null : [
                    'number' => $record->dispatch->tracking_number,
                    'courier' => $record->dispatch->courierProvider?->name,
                ],
            ],
            'evidence' => $record->evidenceFiles->filter(fn ($file) => ! $file->isDraft())->map(fn ($file) => $this->evidence->for($file))->values()->all(),
            'legacy_evidence' => $this->evidence->legacy($record->evidence),
            'financials' => $this->financials->forDamage($record),
            'timeline' => $this->timeline->forDamage($record, false),
            'delivery' => [
                'can_request' => $mayRequest && $payload['can_request_delivery'],
                'quote' => $mayRequest && $payload['can_request_delivery'] ? $this->quotes->for($record) : null,
                'addresses' => $this->addresses($account),
            ],
            'can_confirm_receipt' => $mayRequest && $record->status->value === 'dispatched',
        ]);
    }

    /**
     * The form for reporting a damaged item from one delivered Store Order.
     */
    public function create(Request $request, string $order): Response
    {
        $account = $this->reader($request);
        $record = $this->orderFor($account->id, $order);
        $report = $this->eligibility->for($record);

        return Inertia::render('damages/create', [
            'order' => [
                'id' => $record->public_id,
                'reference' => $record->reference,
                'url' => route('store-orders.show', $record->public_id),
            ],
            'eligibility' => $report,
            'conditions' => array_map(fn (DamageCondition $condition) => ['value' => $condition->value, 'label' => $condition->label()], DamageCondition::cases()),
            'minimum_description' => ReportDamage::MINIMUM_DESCRIPTION,
            'drafts' => $this->drafts($this->actor($request), $record),
            'evidence_limits' => [
                'max_bytes' => $this->uploads->maxBytes(),
                'max_per_case' => $this->uploads->maxPerCase(),
                'mime_types' => array_keys((array) config('case_evidence.mime_types')),
            ],
            'can_report' => $report['eligible'] && $this->mayManage($request, $record),
        ]);
    }

    /**
     * One report, once: the affected item, how many, what is wrong, whether it comes
     * back, and the attachments — sent with a key, so a double click is the same report.
     * The Partner never says who is responsible; staff decide after seeing the goods.
     */
    public function store(Request $request, string $order): RedirectResponse
    {
        $account = $this->reader($request);
        $record = $this->orderFor($account->id, $order);
        $actor = $this->actor($request);

        abort_unless($this->mayManage($request, $record), 403);

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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('damages.report.flash.reported')]);

        return to_route('damages.show', $damage->public_id);
    }

    public function requestDelivery(Request $request, string $damage): RedirectResponse
    {
        $account = $this->reader($request);
        $record = $this->list->find($account, $damage);

        abort_if($record === null, 404);

        $validated = $request->validate([
            'address' => ['required', 'string', 'max:40'],
            'confirmed' => ['accepted'],
        ]);

        $actor = $this->actor($request);

        return $this->attempt(fn () => $this->deliveries->handle($actor, $record, $validated['address']), 'cases.partner.flash.delivery_requested', 'address');
    }

    public function confirmReceipt(Request $request, string $damage): RedirectResponse
    {
        $account = $this->reader($request);
        $record = $this->list->find($account, $damage);

        abort_if($record === null, 404);

        $actor = $this->actor($request);

        return $this->attempt(fn () => $this->receipts->handle($actor, $record), 'cases.partner.flash.received');
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

    /**
     * An order of this account, from one of its own stores — a 404 otherwise.
     */
    protected function orderFor(int $accountId, string $publicId): Order
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
     * Whether this person manages the store the order came through, as for any store action.
     */
    protected function mayManage(Request $request, Order $order): bool
    {
        $website = $order->website_id === null ? null : Website::query()->find($order->website_id);

        return $website !== null && Gate::forUser($request->user())->allows('manage', $website);
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

    protected function reader(Request $request): BusinessAccount
    {
        $account = $this->businessAccountFor($request);
        $user = $request->user();

        abort_unless($user instanceof User && ($user->accountRole()?->hasPermission(AccountPermission::ViewOrders) ?? false), 403);

        return $account;
    }
}
