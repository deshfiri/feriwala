<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Inventory\Actions\OverrideReservation;
use App\Domain\Inventory\Actions\SetReservationWindows;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Domain\Inventory\ReservationWindows;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock reservations: what is held, for whom, until when (contract §6.1.2, P3-26).
 *
 * Reading is `inventory.view`. Overriding a reservation and changing the windows
 * are `inventory.approve`, asked before any rule runs, because both decide how
 * long stock is kept away from every other order.
 */
class ReservationController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(
        protected OverrideReservation $override,
        protected SetReservationWindows $setWindows,
        protected ReservationWindows $windows,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canViewAny($actor), 403);

        $filters = $this->filters($request);
        $term = $filters['search'];

        $reservations = StockReservation::query()
            // Central warehouse stock only — a Supplier-sourced reservation
            // has no `item` and belongs on the Supplier allocation screens
            // instead (D25, P13-21, P13-28).
            ->whereNotNull('stock_item_id')
            ->with([
                'item:id,public_id,warehouse_id,product_id,product_variant_id',
                'item.warehouse:id,code,name',
                'item.product:id,name,sku',
                'item.variant:id,sku',
                'overrider:id,name',
            ])
            ->when($filters['status'] !== null, fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['kind'] !== null, fn (Builder $query) => $query->where('kind', $filters['kind']))
            ->when($term !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('reference', 'ilike', "%{$term}%")
                ->orWhereHas('item.product', fn (Builder $product) => $product->where('sku', 'ilike', "%{$term}%"))
                ->orWhereHas('item.variant', fn (Builder $variant) => $variant->where('sku', 'ilike', "%{$term}%"))))
            // Active and soonest to run out first: those are the ones somebody may act on.
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('expires_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (StockReservation $reservation) => [
                'id' => $reservation->public_id,
                'reference' => $reservation->reference,
                'sku' => $reservation->item?->sku(),
                'product' => $reservation->item?->product->name,
                'item_id' => $reservation->item?->public_id,
                'warehouse' => $reservation->item?->warehouse->code,
                'quantity' => $reservation->quantity,
                'kind' => $reservation->kind->value,
                'kind_label' => __('inventory.reservation_kinds.'.$reservation->kind->value),
                'status' => $reservation->status->value,
                'status_label' => __('inventory.reservation_statuses.'.$reservation->status->value),
                'status_tone' => $reservation->status->tone(),
                'expires_at' => $reservation->expires_at->toIso8601String(),
                'ended_at' => ($reservation->committed_at ?? $reservation->released_at)?->toIso8601String(),
                'release_reason' => $reservation->release_reason,
                'overridden_by' => $reservation->overrider?->name,
            ]);

        return Inertia::render('admin/inventory/reservations', [
            'reservations' => $reservations,
            'filters' => $filters,
            'windows' => [
                'online_minutes' => $this->windows->onlineMinutes(),
                'cod_hours' => $this->windows->codHours(),
                'online_bounds' => [ReservationWindows::MINIMUM_ONLINE_MINUTES, ReservationWindows::MAXIMUM_ONLINE_MINUTES],
                'cod_bounds' => [ReservationWindows::MINIMUM_COD_HOURS, ReservationWindows::MAXIMUM_COD_HOURS],
                'defaults' => [ReservationWindows::DEFAULT_ONLINE_MINUTES, ReservationWindows::DEFAULT_COD_HOURS],
            ],
            'max_extension_hours' => OverrideReservation::MAXIMUM_EXTENSION_HOURS,
            'can' => [
                'override' => InventoryPolicy::canApprove($actor),
            ],
        ]);
    }

    public function release(Request $request, string $reservation): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canApprove($actor), 403);

        $record = $this->reservation($reservation);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.OverrideReservation::MINIMUM_REASON, 'max:1000'],
        ]);

        try {
            $this->override->release($actor, $record, $validated['reason']);
        } catch (InventoryRefused $refused) {
            throw ValidationException::withMessages(['reason' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.reservations.released', ['reference' => $record->reference])]);

        return back();
    }

    public function extend(Request $request, string $reservation): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canApprove($actor), 403);

        $record = $this->reservation($reservation);

        $validated = $request->validate([
            'expires_at' => [
                'required', 'date', 'after:now',
                'before_or_equal:'.CarbonImmutable::now()->addHours(OverrideReservation::MAXIMUM_EXTENSION_HOURS)->toIso8601String(),
            ],
            'reason' => ['required', 'string', 'min:'.OverrideReservation::MINIMUM_REASON, 'max:1000'],
        ]);

        try {
            $this->override->extend($actor, $record, CarbonImmutable::parse($validated['expires_at']), $validated['reason']);
        } catch (InventoryRefused $refused) {
            throw ValidationException::withMessages(['expires_at' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.reservations.extended', ['reference' => $record->reference])]);

        return back();
    }

    public function windows(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless(InventoryPolicy::canApprove($actor), 403);

        $validated = $request->validate([
            'online_minutes' => ['required', 'integer', 'min:'.ReservationWindows::MINIMUM_ONLINE_MINUTES, 'max:'.ReservationWindows::MAXIMUM_ONLINE_MINUTES],
            'cod_hours' => ['required', 'integer', 'min:'.ReservationWindows::MINIMUM_COD_HOURS, 'max:'.ReservationWindows::MAXIMUM_COD_HOURS],
        ]);

        $this->setWindows->handle($actor, (int) $validated['online_minutes'], (int) $validated['cod_hours']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('inventory.reservations.windows_saved')]);

        return back();
    }

    /**
     * @return array{search: string|null, status: string|null, kind: string|null}
     */
    protected function filters(Request $request): array
    {
        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();
        $kind = $request->string('kind')->toString();

        return [
            'search' => $search === '' ? null : mb_substr($search, 0, 120),
            'status' => StockReservationStatus::tryFrom($status)?->value,
            'kind' => ReservationKind::tryFrom($kind)?->value,
        ];
    }

    protected function reservation(string $publicId): StockReservation
    {
        /** @var StockReservation $reservation */
        $reservation = StockReservation::query()->where('public_id', $publicId)->firstOrFail();

        return $reservation;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
