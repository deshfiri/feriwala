<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Enums\AccountPermission;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Cases\Queries\CaseFinancials;
use App\Domain\Cases\Queries\CaseTimeline;
use App\Domain\Cases\Queries\EvidencePayload;
use App\Domain\Cases\Queries\ProductImage;
use App\Domain\Damage\Actions\ConfirmDamageReceivedByPartner;
use App\Domain\Damage\Actions\RequestDamageDelivery;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Damage\Queries\DamageDeliveryQuote;
use App\Domain\Damage\Queries\DamagePayload;
use App\Domain\Damage\Queries\MyDamages;
use App\Http\Controllers\Concerns\RunsCaseActions;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

    protected function reader(Request $request): BusinessAccount
    {
        $account = $this->businessAccountFor($request);
        $user = $request->user();

        abort_unless($user instanceof User && ($user->accountRole()?->hasPermission(AccountPermission::ViewOrders) ?? false), 403);

        return $account;
    }
}
