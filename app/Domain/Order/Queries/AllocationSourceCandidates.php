<?php

namespace App\Domain\Order\Queries;

use App\Domain\Inventory\Models\StockAllocation;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Order\Data\AllocationCandidate;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Order\Models\ProductSourceLink;
use App\Domain\Sourcing\Queries\ResolveProductNetwork;
use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * Every source that could fulfil one order line, for the staff allocation
 * panel.
 *
 * Read-only, and deliberately opinion-free. It reports what each source holds,
 * what it costs and what the line would earn, and says whether a source
 * *could* serve the line — never which one *should*. Choosing is the staff
 * decision the batch requires, and a query that quietly returned only the
 * cheapest would be making it.
 *
 * Ineligible sources are returned too, with the reason. A panel that silently
 * omits the Supplier a member of staff was expecting to see sends them looking
 * for a bug; one that shows "no stock" or "Supplier suspended" answers the
 * question on the spot.
 *
 * Sources come from the ordered Product itself and from every Product linked to
 * it as the same Product ({@see ResolveProductNetwork}), directly or through
 * other links, on the variations staff matched. The network is read **now**,
 * when the panel is opened and again when staff confirm, never from anything
 * frozen on the order line: what was linked when the order was placed is not
 * what decides where it can be fulfilled today. A source reachable by several
 * paths is listed once.
 *
 * **Staff-only output.** Every candidate carries the Supplier's identity, the
 * Supplier Rate and the margin — the three things D25 keeps out of Client and
 * Partner responses, the Storefront API and partner-facing order views. The
 * caller is responsible for being an authorised staff endpoint; nothing here
 * may be spliced into a customer payload.
 */
class AllocationSourceCandidates
{
    public function __construct(protected ResolveProductNetwork $network) {}

    /**
     * @param  OrderItemAllocation|null  $excluding  a specific active allocation
     *                                               to leave out of "already
     *                                               allocated" — the one about
     *                                               to be replaced, when this is
     *                                               a reallocation rather than a
     *                                               new split
     * @return list<AllocationCandidate>
     */
    public function forLine(OrderItem $line, ?OrderItemAllocation $excluding = null): array
    {
        $currency = Currency::from($line->currency_code);
        $platformRate = $line->unit_price;
        $remaining = $this->remainingQuantity($line, $excluding);

        $active = OrderItemAllocation::query()
            ->where('order_item_id', $line->id)
            ->where('status', AllocationStatus::Active)
            ->get();

        $candidates = [
            ...$this->warehouseCandidates($line, $remaining, $platformRate, $currency, $active),
            ...$this->supplierCandidates($line, $remaining, $platformRate, $currency, $active),
            ...$this->linkedProductCandidates($line, $remaining, $platformRate, $currency, $active),

            // Sources staff confirmed one by one, before Products could be
            // linked; still honoured, and still shown as what they are.
            ...$this->linkedCandidates($line, $remaining, $platformRate, $currency, $active),
        ];

        return $this->withoutDuplicates($candidates);
    }

    /**
     * How many other Products this line's Product is linked to, directly or
     * not, whose sources are offered for it. Zero means a unique Product.
     */
    public function linkedProductCount(OrderItem $line): int
    {
        return count($this->network->network($line->product_id));
    }

    /**
     * One entry per source, however many paths led to it. The first wins, and
     * the list is built exact-first, so a source reached both ways keeps the
     * more direct label.
     *
     * @param  list<AllocationCandidate>  $candidates
     * @return list<AllocationCandidate>
     */
    protected function withoutDuplicates(array $candidates): array
    {
        $seen = [];
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = $candidate->sourceType->value.':'.$candidate->sourceId;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * Sources on *other* Products linked to the ordered one as the same
     * Product, on a variation staff matched to the ordered variation — Supplier
     * offers and Central Warehouse stock together.
     *
     * Judged by the links and matches that hold now: one removed since the
     * order was placed makes its sources unavailable again, which is the safe
     * direction. Exact-Product sources are not repeated here (the two methods
     * above already list them); inactive warehouses, suspended offers and
     * non-operational Suppliers are left out rather than shown as options.
     *
     * @param  Collection<int, OrderItemAllocation>  $active
     * @return list<AllocationCandidate>
     */
    protected function linkedProductCandidates(
        OrderItem $line,
        int $quantity,
        Money $platformRate,
        Currency $currency,
        Collection $active,
    ): array {
        $pairs = array_values(array_filter(
            $this->network->compatiblePairs($line->product_id, $line->product_variant_id),
            fn (array $pair) => ! $this->isExactPair($line, $pair[0], $pair[1]),
        ));

        if ($pairs === []) {
            return [];
        }

        $matchesPair = function ($query) use ($pairs) {
            $query->where(function ($inner) use ($pairs) {
                foreach ($pairs as [$productId, $variantId]) {
                    $inner->orWhere(function ($pair) use ($productId, $variantId) {
                        $pair->where('product_id', $productId);
                        $variantId === null
                            ? $pair->whereNull('product_variant_id')
                            : $pair->where('product_variant_id', $variantId);
                    });
                }
            });
        };

        $items = StockItem::query()
            ->with(['warehouse', 'product', 'variant'])
            ->whereHas('warehouse', fn ($query) => $query->where('is_active', true))
            ->tap($matchesPair)
            ->get();

        $offers = SupplierOffer::query()
            ->with(['supplier', 'stock', 'product', 'variant', 'originatingListingItem'])
            ->where('status', 'active')
            ->tap($matchesPair)
            ->get();

        $candidates = [];

        foreach ($items as $item) {
            $candidates[] = $this->warehouseCandidateFromStock($item, $quantity, $platformRate, $currency, $active, 'linked_product');
        }

        foreach ($offers as $offer) {
            $candidates[] = $this->supplierCandidateFromOffer($offer, $quantity, $platformRate, $currency, $active, 'linked_product');
        }

        return array_values(array_filter($candidates));
    }

    protected function isExactPair(OrderItem $line, int $productId, ?int $variantId): bool
    {
        return $productId === $line->product_id && $variantId === $line->product_variant_id;
    }

    /**
     * How much of this line is not yet covered by any active allocation —
     * what a genuinely new split allocation could still take (Advanced Order
     * Management batch, Commit 3). Every eligibility check and margin figure
     * below is judged against this, not the line's full quantity, so an
     * unsplit line (the overwhelming majority) behaves exactly as before:
     * with nothing yet active, remaining equals the line's own quantity.
     *
     * `$excluding` leaves one specific active allocation's own quantity out
     * of what counts as "already allocated" — without it, reallocating a
     * fully-split line's one allocation to a different source would see
     * zero units remaining and wrongly refuse a like-for-like swap.
     */
    public function remainingQuantity(OrderItem $line, ?OrderItemAllocation $excluding = null): int
    {
        $excludingId = $excluding?->id;
        $allocated = (int) OrderItemAllocation::query()
            ->where('order_item_id', $line->id)
            ->where('status', AllocationStatus::Active)
            ->when($excludingId !== null, fn ($query) => $query->whereKeyNot($excludingId))
            ->sum('quantity');

        return max(0, (int) $line->quantity - $allocated);
    }

    /**
     * Sources confirmed, through {@see ProductSourceLink}, to fulfil this
     * product/variation despite being catalogued under a different one —
     * "Recommended / Already Related" beyond the trivial exact-match case
     * the two methods above already cover.
     *
     * @param  Collection<int, OrderItemAllocation>  $active
     * @return list<AllocationCandidate>
     */
    protected function linkedCandidates(
        OrderItem $line,
        int $quantity,
        Money $platformRate,
        Currency $currency,
        Collection $active,
    ): array {
        $links = ProductSourceLink::query()
            ->forOrderedProduct($line->product_id, $line->product_variant_id)
            ->with(['stockItem.warehouse', 'stockItem.product', 'stockItem.variant', 'supplierOffer.supplier', 'supplierOffer.stock', 'supplierOffer.product', 'supplierOffer.variant'])
            ->get();

        $candidates = [];

        foreach ($links as $link) {
            $candidates[] = $link->source_type === AllocationSourceType::Warehouse
                ? ($link->stockItem === null ? null : $this->warehouseCandidateFromStock($link->stockItem, $quantity, $platformRate, $currency, $active, 'linked'))
                : ($link->supplierOffer === null ? null : $this->supplierCandidateFromOffer($link->supplierOffer, $quantity, $platformRate, $currency, $active, 'linked'));
        }

        return array_values(array_filter($candidates));
    }

    /**
     * @param  Collection<int, OrderItemAllocation>  $active
     */
    protected function warehouseCandidateFromStock(
        StockItem $item,
        int $quantity,
        Money $platformRate,
        Currency $currency,
        Collection $active,
        string $matchKind,
    ): ?AllocationCandidate {
        if (! $item->warehouse->is_active) {
            return null;
        }

        $variant = $item->product_variant_id === null ? null : $item->variant;
        $cost = $variant === null ? $item->product->base_cost : ($variant->base_cost ?? $item->product->base_cost);
        $atp = (int) $item->available;
        $isEligible = $atp >= $quantity;

        return new AllocationCandidate(
            sourceType: AllocationSourceType::Warehouse,
            sourceId: $item->public_id,
            sourceLabel: $item->warehouse->name,
            available: (int) $item->available,
            reserved: (int) $item->reserved,
            availableToPromise: $atp,
            unitCost: $cost,
            platformRate: $platformRate,
            expectedMargin: $platformRate->minus($cost)->multipliedBy($quantity),
            currencyCode: $currency->value,
            isEligible: $isEligible,
            ineligibleReason: $isEligible ? null : 'This warehouse holds '.$atp.' of the '.$quantity.' needed.',
            isCurrentlyAllocated: $active->contains(
                fn (OrderItemAllocation $allocation) => $allocation->source_type === AllocationSourceType::Warehouse
                    && $allocation->warehouse_id === $item->warehouse_id,
            ),
            isRelated: true,
            sourceProductId: $item->product_id,
            sourceProductVariantId: $item->product_variant_id,
            sourceProductName: $item->product->name,
            sourceProductSku: $item->sku(),
            matchKind: $matchKind,
            sourceProductBpc: $item->product->sku,
            sourceVariantLabel: $variant?->label(),
        );
    }

    /**
     * @param  Collection<int, OrderItemAllocation>  $active
     */
    protected function supplierCandidateFromOffer(
        SupplierOffer $offer,
        int $quantity,
        Money $platformRate,
        Currency $currency,
        Collection $active,
        string $matchKind,
    ): ?AllocationCandidate {
        if ($offer->status->value !== 'active' || ! $offer->supplier->isOperational()) {
            return null;
        }

        $rate = $offer->supplier_rate;
        $availability = $this->availabilityFor($offer, $quantity);

        $reason = $offer->currency_code !== $currency->value
            ? 'This offer is priced in '.$offer->currency_code.'.'
            : $availability['reason'];

        return new AllocationCandidate(
            sourceType: AllocationSourceType::SupplierOffer,
            sourceId: $offer->public_id,
            sourceLabel: $offer->supplier->business_name,
            available: $availability['available'],
            reserved: $availability['reserved'],
            availableToPromise: $availability['available_to_promise'],
            unitCost: $rate,
            platformRate: $platformRate,
            expectedMargin: $offer->currency_code === $currency->value
                ? $platformRate->minus($rate)->multipliedBy((int) $quantity)
                : Money::zero($currency),
            currencyCode: $offer->currency_code,
            isEligible: $reason === null,
            ineligibleReason: $reason,
            isCurrentlyAllocated: $active->contains(fn (OrderItemAllocation $allocation) => $allocation->supplier_offer_id === $offer->id),
            supplierId: $offer->supplier->public_id,
            supplierName: $offer->supplier->business_name,
            leadTimeDays: $offer->lead_time_days ?? $offer->originatingListingItem?->lead_time_days,
            isPreferred: (bool) $offer->is_preferred,
            supplierStatus: $offer->supplier->status->value,
            offerStatus: $offer->status->value,
            supplyMode: $offer->supply_mode,
            fulfilmentCapacity: $offer->fulfilment_capacity,
            requiresConfirmation: $offer->supply_mode->requiresStaffConfirmationAtAllocation(),
            isRelated: true,
            sourceProductId: $offer->product_id,
            sourceProductVariantId: $offer->product_variant_id,
            sourceProductName: $offer->product->name,
            sourceProductSku: $offer->variant === null ? $offer->product->sku : $offer->variant->sku,
            matchKind: $matchKind,
            sourceProductBpc: $offer->product->sku,
            sourceVariantLabel: $offer->variant?->label(),
            expectedPayable: $offer->currency_code === $currency->value ? $rate->multipliedBy((int) $quantity) : null,
        );
    }

    /**
     * Every active warehouse holding this SKU.
     *
     * Available-to-promise is the `available` bucket plus whatever this order's
     * own account already has set aside on that item — allocated units have
     * left `available` precisely so nobody else can reach them (§19), so
     * leaving them out would under-report what this order can actually take.
     *
     * @param  Collection<int, OrderItemAllocation>  $active
     * @return list<AllocationCandidate>
     */
    protected function warehouseCandidates(
        OrderItem $line,
        int $quantity,
        Money $platformRate,
        Currency $currency,
        Collection $active,
    ): array {
        // A variation may price its own cost; otherwise the product's stands.
        $variant = $line->product_variant_id === null ? null : $line->variant;
        $cost = $variant === null ? $line->product->base_cost : ($variant->base_cost ?? $line->product->base_cost);

        $items = StockItem::query()
            ->with('warehouse')
            ->where('product_id', $line->product_id)
            ->where('product_variant_id', $line->product_variant_id)
            ->whereHas('warehouse', fn ($query) => $query->where('is_active', true))
            ->get();

        $setAside = StockAllocation::query()
            ->whereIn('stock_item_id', $items->pluck('id'))
            ->where('business_account_id', $line->order->business_account_id)
            ->pluck('quantity', 'stock_item_id');

        $candidates = $items->map(function (StockItem $item) use (
            $line, $variant, $quantity, $cost, $platformRate, $currency, $active, $setAside
        ) {
            $atp = (int) $item->available + (int) ($setAside[$item->id] ?? 0);
            $isEligible = $atp >= $quantity;

            return new AllocationCandidate(
                sourceType: AllocationSourceType::Warehouse,
                sourceId: $item->warehouse->public_id,
                sourceLabel: $item->warehouse->name,
                available: (int) $item->available,
                reserved: (int) $item->reserved,
                availableToPromise: $atp,
                unitCost: $cost,
                platformRate: $platformRate,
                expectedMargin: $platformRate->minus($cost)->multipliedBy($quantity),
                currencyCode: $currency->value,
                isEligible: $isEligible,
                ineligibleReason: $isEligible ? null : 'This warehouse holds '.$atp.' of the '.$quantity.' needed.',
                isCurrentlyAllocated: $active->contains(
                    fn (OrderItemAllocation $allocation) => $allocation->source_type === AllocationSourceType::Warehouse
                        && $allocation->warehouse_id === $item->warehouse_id,
                ),
                sourceProductName: $line->product->name,
                sourceProductSku: $variant === null ? $line->product->sku : $variant->sku,
                sourceProductBpc: $line->product->sku,
                sourceVariantLabel: $variant?->label(),
            );
        })->values()->all();

        return array_values($candidates);
    }

    /**
     * Every Supplier offering this variation — not only the preferred one.
     *
     * This is where "five Suppliers, one Central Product" becomes visible: the
     * catalogue holds one product and one variation, and each Supplier's offer
     * is a separate row here with its own rate, availability and lead time.
     *
     * @param  Collection<int, OrderItemAllocation>  $active
     * @return list<AllocationCandidate>
     */
    protected function supplierCandidates(
        OrderItem $line,
        int $quantity,
        Money $platformRate,
        Currency $currency,
        Collection $active,
    ): array {
        $offers = SupplierOffer::query()
            ->with(['supplier', 'stock', 'originatingListingItem', 'product', 'variant'])
            ->where('product_id', $line->product_id)
            ->where('product_variant_id', $line->product_variant_id)
            ->get();

        $candidates = $offers->map(function (SupplierOffer $offer) use (
            $quantity, $platformRate, $currency, $active
        ) {
            $rate = $offer->supplier_rate;
            $availability = $this->availabilityFor($offer, $quantity);

            $reason = match (true) {
                ! $offer->supplier->isOperational() => 'This Supplier is not currently operational.',
                $offer->status->value !== 'active' => 'This offer is suspended.',
                $offer->currency_code !== $currency->value => 'This offer is priced in '.$offer->currency_code.'.',
                default => $availability['reason'],
            };

            return new AllocationCandidate(
                sourceType: AllocationSourceType::SupplierOffer,
                sourceId: $offer->public_id,
                sourceLabel: $offer->supplier->business_name,
                available: $availability['available'],
                reserved: $availability['reserved'],
                availableToPromise: $availability['available_to_promise'],
                unitCost: $rate,
                platformRate: $platformRate,
                // Guarded: a mismatched currency would throw inside Money, and
                // this row exists to explain that rather than to fail on it.
                expectedMargin: $offer->currency_code === $currency->value
                    ? $platformRate->minus($rate)->multipliedBy($quantity)
                    : Money::zero($currency),
                currencyCode: $offer->currency_code,
                isEligible: $reason === null,
                ineligibleReason: $reason,
                isCurrentlyAllocated: $active->contains(fn (OrderItemAllocation $allocation) => $allocation->supplier_offer_id === $offer->id),
                supplierId: $offer->supplier->public_id,
                supplierName: $offer->supplier->business_name,
                leadTimeDays: $offer->lead_time_days ?? $offer->originatingListingItem?->lead_time_days,
                isPreferred: (bool) $offer->is_preferred,
                supplierStatus: $offer->supplier->status->value,
                offerStatus: $offer->status->value,
                supplyMode: $offer->supply_mode,
                fulfilmentCapacity: $offer->fulfilment_capacity,
                requiresConfirmation: $offer->supply_mode->requiresStaffConfirmationAtAllocation(),
                sourceProductName: $offer->product->name,
                sourceProductSku: $offer->variant === null ? $offer->product->sku : $offer->variant->sku,
                sourceProductBpc: $offer->product->sku,
                sourceVariantLabel: $offer->variant?->label(),
                expectedPayable: $offer->currency_code === $currency->value ? $rate->multipliedBy($quantity) : null,
            );
        })->values()->all();

        return array_values($candidates);
    }

    /**
     * What an offer can actually promise for this line -- the real stock
     * figures for `ready_stock`, or an honest zero plus the reason a
     * non-ready-stock offer is still eligible or not, since there is no
     * physical stock to report for it (Supplier Bulk Product Listing batch,
     * correction 8). Never a fabricated stock number either way.
     *
     * @return array{available: int, reserved: int, available_to_promise: int, reason: ?string}
     */
    protected function availabilityFor(SupplierOffer $offer, int $quantity): array
    {
        if ($offer->supply_mode === SupplyMode::ReadyStock) {
            $stock = $offer->stock;
            $atp = (int) ($stock->quantity ?? 0);

            return [
                'available' => $atp,
                'reserved' => (int) ($stock->reserved_quantity ?? 0),
                'available_to_promise' => $atp,
                'reason' => $atp < $quantity ? 'This Supplier has '.$atp.' of the '.$quantity.' needed.' : null,
            ];
        }

        $reason = null;

        if ($offer->fulfilment_capacity !== null) {
            $committed = (int) SupplierFulfilmentCommitment::query()
                ->where('supplier_offer_id', $offer->id)
                ->whereIn('status', array_map(
                    fn (FulfilmentCommitmentStatus $status) => $status->value,
                    SupplierFulfilmentCommitment::nonTerminalStatuses(),
                ))
                ->sum('quantity');

            if ($committed + $quantity > $offer->fulfilment_capacity) {
                $reason = 'This offer can commit to '.$offer->fulfilment_capacity.' units at once; '.$committed.' are already committed.';
            }
        }

        return [
            'available' => 0,
            'reserved' => 0,
            'available_to_promise' => 0,
            'reason' => $reason,
        ];
    }
}
