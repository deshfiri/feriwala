<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Models\PartnerStockCostLot;
use App\Domain\Inventory\Models\PartnerStockItem;
use App\Domain\Inventory\Models\PartnerStockLotMovement;
use App\Domain\Inventory\Models\PartnerStockMovement;
use App\Domain\Order\Models\OrderItem;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * First in, first out: what each unit of a Partner's own stock cost them.
 *
 * {@see PartnerStock::move()} hands every movement here, inside its own
 * transaction and with the stock row already locked, so the lots and the
 * `available` bucket can never drift apart:
 *
 *   - a paid purchase gives a new lot, costed from its own line;
 *   - setting units aside for an order takes them from the oldest lots that
 *     still have some, and records each lot and quantity against that hold;
 *   - giving the hold back returns exactly those units to exactly those lots;
 *   - a sold unit coming back onto the shelf goes back to a lot the sale took
 *     it from — the newest of them first;
 *   - anything else entering `available` has no purchase behind it, so it is a
 *     lot whose cost is unknown; anything else leaving takes the oldest first.
 *
 * **Costs are exact.** A lot's cost is its purchase line, net of discount and
 * excluding tax. The first `k` units issued from a lot cost that figure times
 * `k / purchased`, rounded half away from zero, and each issue costs the
 * difference between two such points — so the units of a lot always add back to
 * exactly what the line cost, and nothing is averaged.
 */
class PartnerStockCostLots
{
    /**
     * Follow one movement of a Partner's stock. Only movements into or out of
     * `available` touch a lot.
     */
    public function follow(
        PartnerStockItem $item,
        ?StockBucket $from,
        ?StockBucket $to,
        int $quantity,
        PartnerStockMovementType $type,
        MovementContext $context,
        PartnerStockMovement $movement,
    ): void {
        $intoAvailable = $to === StockBucket::Available;
        $outOfAvailable = $from === StockBucket::Available;

        if (! $intoAvailable && ! $outOfAvailable) {
            return;
        }

        $reservationId = $context->sourceType === 'stock_reservation' ? $context->sourceId : null;

        match (true) {
            $type === PartnerStockMovementType::PurchaseReceived => $this->receive($item, $quantity, $context, $movement),
            $outOfAvailable && $to === StockBucket::Reserved && $reservationId !== null => $this->allocate($item, $reservationId, $quantity, $movement),
            $intoAvailable && $from === StockBucket::Reserved && $reservationId !== null => $this->release($item, $reservationId, $quantity, $context, $movement),
            $intoAvailable && $context->orderItemId !== null => $this->returnToShelf($item, $context->orderItemId, $quantity, $context, $movement),
            $intoAvailable => $this->adjustIn($item, $quantity, $context, $movement),
            default => $this->takeOut(
                $item,
                $quantity,
                $type === PartnerStockMovementType::PurchaseReversed ? PartnerStockLotMovement::TYPE_REVERSED : PartnerStockLotMovement::TYPE_ADJUSTED,
                $context,
                $movement,
            ),
        };
    }

    /**
     * What one hold took, lot by lot and net of anything given back, oldest lot
     * first.
     *
     * @return Collection<int, array{lot: PartnerStockCostLot, quantity: int, cost: Money|null}>
     */
    public function allocationsFor(int $reservationId): Collection
    {
        $rows = PartnerStockLotMovement::query()
            ->where('stock_reservation_id', $reservationId)
            ->whereIn('type', [PartnerStockLotMovement::TYPE_ALLOCATED, PartnerStockLotMovement::TYPE_RELEASED])
            ->get()
            ->groupBy('partner_stock_cost_lot_id');

        $lots = PartnerStockCostLot::query()->whereIn('id', $rows->keys())->get()->keyBy('id');

        /** @var Collection<int, array{lot: PartnerStockCostLot, quantity: int, cost: Money|null}> $allocations */
        $allocations = $rows
            ->map(function (Collection $movements, int $lotId) use ($lots) {
                /** @var PartnerStockCostLot $lot */
                $lot = $lots[$lotId];
                $quantity = -1 * (int) $movements->sum('quantity');
                $cost = null;

                if ($lot->cost_is_known) {
                    $cost = Money::zero(Currency::from($lot->currency_code));

                    foreach ($movements as $movement) {
                        $signed = $movement->cost ?? Money::zero($cost->currency);
                        $cost = $movement->quantity < 0 ? $cost->plus($signed) : $cost->minus($signed);
                    }
                }

                return ['lot' => $lot, 'quantity' => $quantity, 'cost' => $cost];
            })
            ->filter(fn (array $allocation) => $allocation['quantity'] > 0)
            ->sortBy(fn (array $allocation) => [$allocation['lot']->purchased_at->getTimestamp(), $allocation['lot']->id])
            ->values();

        return $allocations;
    }

    /**
     * What the units of one sale that were kept cost — every unit of the line
     * less the `$returned` that came back, those taken from the newest lots of
     * the sale first.
     *
     * Null when the cost cannot be proven: some units were never taken from a
     * lot (sold before lots existed), or came from a lot whose cost is unknown.
     */
    public function keptCost(OrderItem $line, int $returned): ?Money
    {
        if ($line->stock_reservation_id === null) {
            return null;
        }

        $allocations = $this->allocationsFor($line->stock_reservation_id);

        if ((int) $allocations->sum('quantity') !== $line->quantity) {
            return null;
        }

        $total = Money::zero($line->unit_price->currency);
        $toAttribute = max(0, $returned);

        // Newest lot first for what came back; the rest is what was kept.
        foreach ($allocations->reverse() as $allocation) {
            if ($allocation['cost'] === null) {
                return null;
            }

            $back = min($toAttribute, $allocation['quantity']);
            $toAttribute -= $back;

            $kept = $allocation['quantity'] - $back;
            $total = $total->plus($this->share($allocation['cost'], $allocation['quantity'], 0, $kept));
        }

        return $total;
    }

    /**
     * Units a paid purchase line gave. A line that cannot be found — a movement
     * written without its source — becomes a lot whose cost is unknown.
     */
    protected function receive(PartnerStockItem $item, int $quantity, MovementContext $context, PartnerStockMovement $movement): void
    {
        $line = $context->sourceType === 'order_item' && $context->sourceId !== null
            ? OrderItem::query()->with('order')->find($context->sourceId)
            : null;

        if ($line === null) {
            $this->adjustIn($item, $quantity, $context, $movement);

            return;
        }

        $net = $this->netCostOf($line);

        $lot = PartnerStockCostLot::query()->firstOrCreate(
            ['source_order_item_id' => $line->id],
            [
                'business_account_id' => $item->business_account_id,
                'partner_stock_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'source_order_id' => $line->order_id,
                'provenance' => PartnerStockCostLot::PROVENANCE_PURCHASE,
                'purchased_quantity' => $quantity,
                'remaining_quantity' => $quantity,
                'currency_code' => $net->currency->value,
                'net_cost' => $net,
                'unit_cost' => $this->share($net, $quantity, 0, 1),
                'cost_is_known' => true,
                'purchased_at' => $line->order->paid_at ?? CarbonImmutable::now(),
            ],
        );

        if (! $lot->wasRecentlyCreated) {
            return;
        }

        $this->record($lot, PartnerStockLotMovement::TYPE_RECEIVED, $quantity, $net, 'lot-received:'.$lot->id, $movement, $context);
    }

    /**
     * A purchase line's cost to the Partner: what they paid for the goods after
     * the line's discount, without the tax inside the price or added to it.
     */
    public function netCostOf(OrderItem $line): Money
    {
        return $line->line_subtotal->minus($line->discount)->minus($line->tax_included);
    }

    /**
     * Take units for a hold from the oldest lots that still have some.
     *
     * The stock row has already checked there are enough units; when the lots
     * hold fewer (units bought before lots existed and not yet opened), the hold
     * keeps the units it could take and the rest have no lot — their cost is then
     * unproven, which {@see keptCost()} reports rather than guesses.
     */
    protected function allocate(PartnerStockItem $item, int $reservationId, int $quantity, PartnerStockMovement $movement): void
    {
        $needed = $quantity;

        foreach ($this->openLots($item) as $lot) {
            if ($needed === 0) {
                break;
            }

            $take = min($needed, $lot->remaining_quantity);
            $cost = $this->issue($lot, $take);

            $this->record(
                $lot, PartnerStockLotMovement::TYPE_ALLOCATED, -$take, $cost,
                'lot-allocated:'.$reservationId.':'.$lot->id, $movement, reservationId: $reservationId,
            );

            $needed -= $take;
        }
    }

    /**
     * A hold given back: every unit it took goes back to the lot it came from.
     * Units it held without a lot (set aside before lots existed) come back as
     * units whose cost is unknown.
     */
    protected function release(PartnerStockItem $item, int $reservationId, int $quantity, MovementContext $context, PartnerStockMovement $movement): void
    {
        $allocations = $this->allocationsFor($reservationId);
        $withoutLot = $quantity - (int) $allocations->sum('quantity');

        if ($withoutLot > 0) {
            $this->adjustIn($item, $withoutLot, $context, $movement);
        }

        foreach ($allocations as $allocation) {
            /** @var PartnerStockCostLot $lot */
            $lot = PartnerStockCostLot::query()->lockForUpdate()->findOrFail($allocation['lot']->id);
            $lot->forceFill(['remaining_quantity' => $lot->remaining_quantity + $allocation['quantity']])->save();

            $this->record(
                $lot, PartnerStockLotMovement::TYPE_RELEASED, $allocation['quantity'], $allocation['cost'],
                'lot-released:'.$reservationId.':'.$lot->id, $movement, reservationId: $reservationId,
            );
        }
    }

    /**
     * Sold units back on the shelf: to the lots the sale took them from, newest
     * first, never more to a lot than the sale took from it. Units of a sale that
     * took nothing from a lot come back with no cost known.
     */
    protected function returnToShelf(PartnerStockItem $item, int $orderItemId, int $quantity, MovementContext $context, PartnerStockMovement $movement): void
    {
        $line = OrderItem::query()->find($orderItemId);
        $left = $quantity;

        if ($line?->stock_reservation_id !== null) {
            $alreadyBack = PartnerStockLotMovement::query()
                ->where('order_item_id', $line->id)
                ->where('type', PartnerStockLotMovement::TYPE_RETURNED)
                ->get()
                ->groupBy('partner_stock_cost_lot_id')
                ->map(fn (Collection $rows) => (int) $rows->sum('quantity'));

            foreach ($this->allocationsFor($line->stock_reservation_id)->reverse() as $allocation) {
                if ($left === 0) {
                    break;
                }

                $lotId = $allocation['lot']->id;
                $back = (int) ($alreadyBack[$lotId] ?? 0);
                $give = min($left, $allocation['quantity'] - $back);

                if ($give <= 0) {
                    continue;
                }

                /** @var PartnerStockCostLot $lot */
                $lot = PartnerStockCostLot::query()->lockForUpdate()->findOrFail($lotId);
                $lot->forceFill(['remaining_quantity' => $lot->remaining_quantity + $give])->save();

                $cost = $allocation['cost'] === null ? null : $this->share($allocation['cost'], $allocation['quantity'], $back, $back + $give);

                $this->record(
                    $lot, PartnerStockLotMovement::TYPE_RETURNED, $give, $cost,
                    'lot-returned:'.$movement->id.':'.$lot->id, $movement, $context, orderItemId: $line->id,
                );

                $left -= $give;
            }
        }

        if ($left > 0) {
            $this->adjustIn($item, $left, $context, $movement);
        }
    }

    /**
     * Units entering `available` with no purchase behind them: a lot of their
     * own, whose cost is unknown.
     */
    protected function adjustIn(PartnerStockItem $item, int $quantity, MovementContext $context, PartnerStockMovement $movement): void
    {
        $key = 'lot-adjusted-in:'.$movement->id;

        if (PartnerStockLotMovement::query()->where('idempotency_key', $key)->exists()) {
            return;
        }

        $lot = PartnerStockCostLot::create([
            'business_account_id' => $item->business_account_id,
            'partner_stock_item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'provenance' => PartnerStockCostLot::PROVENANCE_ADJUSTMENT,
            'purchased_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'currency_code' => Currency::base()->value,
            'net_cost' => null,
            'unit_cost' => null,
            'cost_is_known' => false,
            'purchased_at' => CarbonImmutable::now(),
        ]);

        $this->record($lot, PartnerStockLotMovement::TYPE_ADJUSTED, $quantity, null, $key, $movement, $context);
    }

    /**
     * Units leaving `available` for no sale: the oldest lots first.
     */
    protected function takeOut(PartnerStockItem $item, int $quantity, string $type, MovementContext $context, PartnerStockMovement $movement): void
    {
        $needed = $quantity;

        foreach ($this->openLots($item) as $lot) {
            if ($needed === 0) {
                break;
            }

            $take = min($needed, $lot->remaining_quantity);
            $cost = $this->issue($lot, $take);

            $this->record($lot, $type, -$take, $cost, 'lot-out:'.$movement->id.':'.$lot->id, $movement, $context);

            $needed -= $take;
        }
    }

    /**
     * The lots of one stock row that still hold units, oldest first, locked.
     *
     * @return Collection<int, PartnerStockCostLot>
     */
    protected function openLots(PartnerStockItem $item): Collection
    {
        return PartnerStockCostLot::query()
            ->where('partner_stock_item_id', $item->id)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('purchased_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Take `$quantity` units out of a locked lot, and say what they cost.
     */
    protected function issue(PartnerStockCostLot $lot, int $quantity): ?Money
    {
        $cost = $lot->cost_is_known && $lot->net_cost !== null
            ? $this->share($lot->net_cost, $lot->purchased_quantity, $lot->issued_quantity, $lot->issued_quantity + $quantity)
            : null;

        $lot->forceFill([
            'remaining_quantity' => $lot->remaining_quantity - $quantity,
            'issued_quantity' => $lot->issued_quantity + $quantity,
        ])->save();

        return $cost;
    }

    /**
     * What units `$from`..`$to` of `$units` cost, when all `$units` together cost
     * `$whole`: the difference of two cumulative points, each `$whole × k / $units`
     * rounded half away from zero. Consecutive shares therefore always add back
     * to exactly `$whole`.
     */
    public function share(Money $whole, int $units, int $from, int $to): Money
    {
        return $this->point($whole, $units, $to)->minus($this->point($whole, $units, $from));
    }

    protected function point(Money $whole, int $units, int $k): Money
    {
        if ($k === 0) {
            return Money::zero($whole->currency);
        }

        $exact = bcdiv(bcmul($whole->toDecimal(), (string) $k, 2), (string) $units, 20);

        return Money::fromDecimal($exact, $whole->currency);
    }

    protected function record(
        PartnerStockCostLot $lot,
        string $type,
        int $quantity,
        ?Money $cost,
        string $key,
        PartnerStockMovement $movement,
        ?MovementContext $context = null,
        ?int $reservationId = null,
        ?int $orderItemId = null,
    ): void {
        PartnerStockLotMovement::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'partner_stock_cost_lot_id' => $lot->id,
                'partner_stock_item_id' => $lot->partner_stock_item_id,
                'type' => $type,
                'quantity' => $quantity,
                'currency_code' => $lot->currency_code,
                'cost' => $cost,
                'stock_reservation_id' => $reservationId,
                'order_item_id' => $orderItemId,
                'partner_stock_movement_id' => $movement->id,
                'reason' => $context?->reason,
                'actor_id' => $context?->actorId,
            ],
        );
    }
}
