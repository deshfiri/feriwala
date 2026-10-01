<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Supplier\Actions\AdvanceSupplierFulfilmentCommitment;
use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Http\Controllers\Controller;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * A Supplier's own fulfilment commitments (Advanced Order Management batch,
 * Commit 2) — the self-service counterpart to the admin Order Detail panel,
 * built in the Supplier Bulk Product Listing batch, D25's authorization
 * boundary.
 *
 * Scoped through the authenticated Supplier's own offers, the same `whereHas`
 * pattern {@see AllocationController}/{@see PayableController} already use:
 * a commitment belonging to a different Supplier is a 404, never a chance to
 * act on it. The payload is deliberately narrow — product, SKU, quantity,
 * dates, status, this Supplier's own rate context — never the buyer's
 * identity or address, and never another Supplier's rate (nothing here ever
 * touches another offer).
 */
class FulfilmentController extends Controller
{
    public const PER_PAGE = 20;

    public function __construct(protected AdvanceSupplierFulfilmentCommitment $advance) {}

    public function index(Request $request): Response
    {
        $supplier = $this->supplier($request);

        $commitments = SupplierFulfilmentCommitment::query()
            ->whereHas('offer', fn ($query) => $query->where('supplier_id', $supplier->id))
            ->with(['offer', 'allocation.orderItem'])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (SupplierFulfilmentCommitment $commitment) => $this->row($commitment));

        return Inertia::render('supplier/fulfilment/index', ['commitments' => $commitments]);
    }

    public function show(Request $request, string $commitment): Response
    {
        $supplier = $this->supplier($request);
        $model = $this->commitment($supplier, $commitment, ['offer', 'allocation.orderItem', 'statusHistory']);

        return Inertia::render('supplier/fulfilment/show', ['commitment' => $this->row($model, detailed: true)]);
    }

    /**
     * Confirm, decline, start preparing or mark ready — the Supplier's own
     * moves on their commitment. Decline (= the existing `Cancelled`
     * transition, recorded with this Supplier as its source — there is no
     * separate Declined status) requires a reason. A Supplier never marks a
     * commitment Failed themselves; that stays a staff-initiated move.
     */
    public function advance(Request $request, string $commitment): RedirectResponse
    {
        $supplier = $this->supplier($request);
        $model = $this->commitment($supplier, $commitment, ['offer']);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['confirm', 'decline', 'start_preparing', 'mark_ready'])],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:action,decline'],
            'expected_ready_at' => ['nullable', 'date', 'after:now'],
        ]);

        $expectedReadyAt = isset($validated['expected_ready_at'])
            ? CarbonImmutable::parse($validated['expected_ready_at'])
            : null;

        try {
            match ($validated['action']) {
                'confirm' => $this->advance->confirm($model, null, SupplierStatusChangeSource::Supplier, $expectedReadyAt),
                'decline' => $this->advance->cancel($model, null, $validated['reason'], SupplierStatusChangeSource::Supplier),
                'start_preparing' => $this->advance->startPreparing($model, null, SupplierStatusChangeSource::Supplier),
                'mark_ready' => $this->advance->markReady($model, null, SupplierStatusChangeSource::Supplier),
                default => throw new InvalidArgumentException("Unknown fulfilment commitment action [{$validated['action']}]."),
            };
        } catch (InvalidArgumentException|IllegalStateTransition $exception) {
            throw ValidationException::withMessages(['action' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('supplier.fulfilment.updated')]);

        return back();
    }

    /**
     * @param  array<int, string>  $with
     */
    protected function commitment(Supplier $supplier, string $publicId, array $with = []): SupplierFulfilmentCommitment
    {
        /** @var SupplierFulfilmentCommitment $model */
        $model = SupplierFulfilmentCommitment::query()
            ->whereHas('offer', fn ($query) => $query->where('supplier_id', $supplier->id))
            ->where('public_id', $publicId)
            ->with($with)
            ->firstOrFail();

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(SupplierFulfilmentCommitment $commitment, bool $detailed = false): array
    {
        $offer = $commitment->offer;
        $item = $commitment->allocation->orderItem;

        // The verbs a Supplier sees for themselves -- "Decline", never
        // "Cancel" -- derived from the same transitionsTo() the admin panel
        // reads, so no route this Supplier may legally take is ever missing
        // a button. `fail` is never offered: a Supplier does not mark their
        // own commitment Failed.
        $actionsByTransition = [
            FulfilmentCommitmentStatus::Confirmed->value => 'confirm',
            FulfilmentCommitmentStatus::Preparing->value => 'start_preparing',
            FulfilmentCommitmentStatus::Ready->value => 'mark_ready',
            FulfilmentCommitmentStatus::Cancelled->value => 'decline',
        ];

        return [
            'id' => $commitment->public_id,
            'reference' => $commitment->reference,
            'status' => $commitment->status->value,
            'status_label' => $commitment->status->label(),
            'status_tone' => $commitment->status->tone(),
            'is_terminal' => $commitment->status->isTerminal(),
            'product_name' => $item->product_name,
            'sku' => $item->sku,
            'quantity' => $commitment->quantity,
            'supply_mode' => $offer->supply_mode->value,
            'supply_mode_label' => $offer->supply_mode->label(),
            'lead_time_days' => $offer->lead_time_days,
            'due_at' => $commitment->due_at?->toIso8601String(),
            'confirmation_due_at' => $commitment->confirmation_due_at?->toIso8601String(),
            // Asked for only when nothing already fixes a date -- the offer
            // declared neither an expected-availability date nor a lead time.
            'needs_expected_ready_at' => $offer->supply_mode !== SupplyMode::ReadyStock
                && $offer->expected_availability_at === null
                && $offer->lead_time_days === null,
            'expected_ready_at' => $commitment->expected_ready_at?->toIso8601String(),
            'failed_reason' => $commitment->failed_reason,
            'cancelled_reason' => $commitment->cancelled_reason,
            'available_actions' => array_values(array_filter(array_map(
                fn (FulfilmentCommitmentStatus $to) => $actionsByTransition[$to->value] ?? null,
                $commitment->status->transitionsTo(),
            ))),
            'history' => $detailed ? $commitment->statusHistory->map(fn ($change) => [
                'previous_status' => $change->previous_status?->label(),
                'new_status' => $change->new_status->label(),
                'changed_by' => $change->source->label(),
                'changed_at' => $change->changed_at->toIso8601String(),
                'reason' => $change->reason,
            ])->all() : null,
        ];
    }

    protected function supplier(Request $request): Supplier
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        return $supplier;
    }
}
