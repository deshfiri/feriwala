<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Actions\RecordManualCourierStatusUpdate;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Enums\ShipmentStatusChangeSource;
use App\Domain\Courier\Exceptions\CourierProviderUnavailable;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use App\Integrations\Courier\CourierManager;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The courier-neutral shipment domain's staff screens (Advanced Order
 * Management batch, Commit 5; §21).
 *
 * Only the manual provider is actually reachable in this batch (D8) --
 * {@see store()} refuses any other with the same
 * {@see CourierProviderUnavailable} message the action itself throws, so the
 * UI and this controller never disagree about why a provider cannot be used.
 */
class ShipmentController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(
        protected CreateShipmentFromAllocations $create,
        protected RecordManualCourierStatusUpdate $advance,
        protected CourierManager $courier,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('viewAny', Shipment::class);

        $status = $request->string('status')->toString();
        $status = $status === '' ? null : $status;

        $shipments = Shipment::query()
            ->with(['order:id,public_id,reference', 'courierProvider:id,code,name'])
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Shipment $shipment) => [
                'id' => $shipment->public_id,
                'reference' => $shipment->reference,
                'order' => [
                    'id' => $shipment->order->public_id,
                    'reference' => $shipment->order->reference,
                ],
                'provider' => $shipment->courierProvider->name,
                'status' => $shipment->status->value,
                'status_label' => $shipment->status->label(),
                'status_tone' => $shipment->status->tone(),
                'tracking_number' => $shipment->tracking_number,
                'delivery_charge' => $shipment->delivery_charge->jsonSerialize(),
                'created_at' => $shipment->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/shipments/index', [
            'shipments' => $shipments,
            'filters' => ['status' => $status],
            'statuses' => array_map(
                fn (OrderCourierStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                array_values(array_filter(OrderCourierStatus::cases(), fn (OrderCourierStatus $status) => $status !== OrderCourierStatus::Unassigned)),
            ),
        ]);
    }

    public function show(Request $request, string $shipment): Response
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('viewAny', Shipment::class);

        $record = $this->shipment($shipment);
        $record->load([
            'order:id,public_id,reference,status',
            'courierProvider:id,code,name',
            'packages',
            'trackingEvents',
            'statusHistory.changedBy:id,name',
            'cancelledBy:id,name',
        ]);

        $canManage = Gate::forUser($actor)->allows('manageShipments', Shipment::class);

        return Inertia::render('admin/shipments/show', [
            'shipment' => [
                'id' => $record->public_id,
                'reference' => $record->reference,
                'order' => [
                    'id' => $record->order->public_id,
                    'reference' => $record->order->reference,
                ],
                'provider' => $record->courierProvider->name,
                'tracking_number' => $record->tracking_number,
                'delivery_charge' => $record->delivery_charge->jsonSerialize(),
                'cod_amount' => $record->cod_amount?->jsonSerialize(),
                'status' => $record->status->value,
                'status_label' => $record->status->label(),
                'status_tone' => $record->status->tone(),
                'is_terminal' => $record->status->isTerminal(),
                'can_manage' => $canManage,
                'available_actions' => $canManage
                    ? array_map(fn (OrderCourierStatus $to) => $to->value, $record->status->transitionsTo())
                    : [],
                'pickup_requested_at' => $record->pickup_requested_at?->toIso8601String(),
                'cancelled_by' => $record->cancelledBy?->name,
                'cancellation_reason' => $record->cancellation_reason,
                'cancelled_at' => $record->cancelled_at?->toIso8601String(),
                'packages' => $record->packages->map(fn ($package) => [
                    'weight' => $package->weight,
                    'length' => $package->length,
                    'width' => $package->width,
                    'height' => $package->height,
                    'package_size' => $package->package_size,
                ])->all(),
                'tracking_events' => $record->trackingEvents->map(fn ($event) => [
                    'event_code' => $event->event_code,
                    'description' => $event->description,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                    'source' => $event->source,
                ])->all(),
                'history' => $record->statusHistory->map(fn ($change) => [
                    'previous_status' => $change->previous_status?->label(),
                    'new_status' => $change->new_status->label(),
                    'changed_by' => $this->historyActorLabel($change->changedBy, $change->source),
                    'changed_at' => $change->changed_at->toIso8601String(),
                    'reason' => $change->reason,
                ])->all(),
            ],
        ]);
    }

    /**
     * Raise a shipment for an order (§21). Reachable from Order Detail's
     * "Create shipment" dialog.
     */
    public function store(Request $request, string $order): RedirectResponse
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('manageShipments', Shipment::class);

        $record = $this->order($order);

        $validated = $request->validate([
            'provider' => ['required', Rule::enum(CourierProviderCode::class)],
            'tracking_number' => ['nullable', 'string', 'max:64'],
            'delivery_charge' => ['required', new DecimalAmountRule(Currency::BDT)],
            'cod_amount' => ['nullable', new DecimalAmountRule(Currency::BDT)],
            'packages' => ['required', 'array', 'min:1'],
            'packages.*.weight' => ['nullable', 'numeric', 'gt:0'],
            'packages.*.length' => ['nullable', 'numeric', 'gt:0'],
            'packages.*.width' => ['nullable', 'numeric', 'gt:0'],
            'packages.*.height' => ['nullable', 'numeric', 'gt:0'],
            'packages.*.package_size' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $shipment = $this->create->handle(
                $record,
                CourierProviderCode::from($validated['provider']),
                DecimalAmount::parse($validated['delivery_charge'], Currency::BDT),
                $validated['packages'],
                $actor,
                $validated['tracking_number'] ?? null,
                DecimalAmount::parseOrNull($validated['cod_amount'] ?? null, Currency::BDT),
            );
        } catch (CourierProviderUnavailable $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        } catch (IllegalStateTransition $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('courier.admin.shipment_created')]);

        return redirect()->route('admin.shipments.show', $shipment->public_id);
    }

    /**
     * Staff recording what a courier has told them (§21, D8).
     */
    public function advanceStatus(Request $request, string $shipment): RedirectResponse
    {
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize('manageShipments', Shipment::class);

        $record = $this->shipment($shipment);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(OrderCourierStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->advance->handle(
                $record,
                OrderCourierStatus::from($validated['status']),
                $actor,
                $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException|IllegalStateTransition $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('courier.admin.status_updated')]);

        return back();
    }

    protected function order(string $publicId): Order
    {
        /** @var Order $order */
        $order = Order::query()->where('public_id', $publicId)->firstOrFail();

        return $order;
    }

    protected function shipment(string $publicId): Shipment
    {
        /** @var Shipment $shipment */
        $shipment = Shipment::query()->where('public_id', $publicId)->firstOrFail();

        return $shipment;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }

    /**
     * Who to show for a shipment history row — the staff member's name when
     * one acted, else the source itself ("System", "Courier webhook").
     */
    protected function historyActorLabel(?User $changedBy, ShipmentStatusChangeSource $source): string
    {
        if ($changedBy === null) {
            return $source->label();
        }

        return $changedBy->name;
    }
}
