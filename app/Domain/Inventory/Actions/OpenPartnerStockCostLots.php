<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Models\PartnerStockCostLot;
use App\Domain\Inventory\Models\PartnerStockItem;
use App\Domain\Inventory\Models\PartnerStockLotMovement;
use App\Domain\Inventory\Models\PartnerStockMovement;
use App\Domain\Inventory\PartnerStockCostLots;
use App\Domain\Order\Models\OrderItem;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

/**
 * Gives Partner stock bought before cost lots existed its lots.
 *
 * For each stock row:
 *
 *   - every purchase line that gave it units and has no lot yet gets one, with
 *     its frozen net cost — and **nothing remaining**: those units were sold,
 *     reserved or are on the shelf, and which of them is which was never
 *     recorded. They are kept so finance can name them when settling a sale
 *     whose cost has to be decided;
 *   - the units on the shelf that no lot accounts for get one **opening** lot.
 *     Its cost is known only when every purchase of that unit was at one and the
 *     same net price — then any unit cost exactly that. Otherwise its cost is
 *     unknown, and a sale from it waits for finance instead of being guessed.
 *
 * Safe to run again: a purchase line has at most one lot, and an opening lot is
 * written only for units no lot covers. Each stock row is handled under its own
 * lock, so a sale arriving meanwhile waits rather than racing it.
 */
class OpenPartnerStockCostLots
{
    public function __construct(
        protected DatabaseManager $database,
        protected PartnerStockCostLots $lots,
    ) {}

    /**
     * @return array{items: int, purchase_lots: int, opening_lots: int, opening_units: int, unknown_cost_units: int}
     */
    public function handle(bool $apply): array
    {
        $report = ['items' => 0, 'purchase_lots' => 0, 'opening_lots' => 0, 'opening_units' => 0, 'unknown_cost_units' => 0];

        PartnerStockItem::query()->orderBy('id')->chunkById(200, function (Collection $items) use ($apply, &$report): void {
            foreach ($items as $item) {
                $row = $apply
                    ? $this->database->transaction(fn () => $this->open($item, true))
                    : $this->open($item, false);

                $report['items'] += ($row['purchase_lots'] + $row['opening_lots']) > 0 ? 1 : 0;
                $report['purchase_lots'] += $row['purchase_lots'];
                $report['opening_lots'] += $row['opening_lots'];
                $report['opening_units'] += $row['opening_units'];
                $report['unknown_cost_units'] += $row['unknown_cost_units'];
            }
        });

        return $report;
    }

    /**
     * @return array{purchase_lots: int, opening_lots: int, opening_units: int, unknown_cost_units: int}
     */
    protected function open(PartnerStockItem $item, bool $apply): array
    {
        /** @var PartnerStockItem $locked */
        $locked = $apply ? PartnerStockItem::query()->lockForUpdate()->findOrFail($item->id) : $item;

        $purchases = $this->purchasesOf($locked);
        $missing = $purchases->filter(fn (array $purchase) => ! PartnerStockCostLot::query()->where('source_order_item_id', $purchase['line']->id)->exists());

        $covered = (int) PartnerStockCostLot::query()->where('partner_stock_item_id', $locked->id)->sum('remaining_quantity');
        $uncovered = max(0, $locked->available - $covered);
        $single = $this->singleUnitCost($purchases);

        if ($apply) {
            foreach ($missing as $purchase) {
                $this->writeHistoricalLot($locked, $purchase['line'], $purchase['quantity'], $purchase['at']);
            }

            if ($uncovered > 0) {
                $this->writeOpeningLot($locked, $uncovered, $single);
            }
        }

        return [
            'purchase_lots' => $missing->count(),
            'opening_lots' => $uncovered > 0 ? 1 : 0,
            'opening_units' => $uncovered,
            'unknown_cost_units' => $uncovered > 0 && $single === null ? $uncovered : 0,
        ];
    }

    /**
     * Every purchase line that gave this stock row units, oldest first.
     *
     * @return Collection<int, array{line: OrderItem, quantity: int, at: CarbonImmutable}>
     */
    protected function purchasesOf(PartnerStockItem $item): Collection
    {
        $movements = PartnerStockMovement::query()
            ->where('partner_stock_item_id', $item->id)
            ->where('type', PartnerStockMovementType::PurchaseReceived->value)
            ->where('source_type', 'order_item')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $lines = OrderItem::query()->with('order')->whereIn('id', $movements->pluck('source_id'))->get()->keyBy('id');

        $purchases = collect();

        foreach ($movements as $movement) {
            $line = $movement->source_id === null ? null : $lines->get($movement->source_id);

            if ($line instanceof OrderItem) {
                $purchases->push([
                    'line' => $line,
                    'quantity' => $movement->quantity,
                    'at' => $line->order->paid_at ?? $movement->occurred_at,
                ]);
            }
        }

        return $purchases;
    }

    /**
     * The one net unit cost every purchase shares, as a [whole, units] pair —
     * or null when they differ, or there were none.
     *
     * @param  Collection<int, array{line: OrderItem, quantity: int, at: CarbonImmutable}>  $purchases
     * @return array{0: Money, 1: int}|null
     */
    protected function singleUnitCost(Collection $purchases): ?array
    {
        if ($purchases->isEmpty()) {
            return null;
        }

        $first = $purchases->first()['line'];
        $firstNet = $this->lots->netCostOf($first);

        foreach ($purchases as $purchase) {
            $net = $this->lots->netCostOf($purchase['line']);

            if (bccomp(bcmul($net->toDecimal(), (string) $first->quantity, 2), bcmul($firstNet->toDecimal(), (string) $purchase['line']->quantity, 2), 2) !== 0) {
                return null;
            }
        }

        return [$firstNet, $first->quantity];
    }

    protected function writeHistoricalLot(PartnerStockItem $item, OrderItem $line, int $quantity, CarbonImmutable $at): void
    {
        $net = $this->lots->netCostOf($line);

        PartnerStockCostLot::query()->firstOrCreate(
            ['source_order_item_id' => $line->id],
            [
                'business_account_id' => $item->business_account_id,
                'partner_stock_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'source_order_id' => $line->order_id,
                'provenance' => PartnerStockCostLot::PROVENANCE_PURCHASE,
                'purchased_quantity' => $quantity,
                'remaining_quantity' => 0,
                'issued_quantity' => $quantity,
                'currency_code' => $net->currency->value,
                'net_cost' => $net,
                'unit_cost' => $this->lots->share($net, $quantity, 0, 1),
                'cost_is_known' => true,
                'purchased_at' => $at,
            ],
        );
    }

    /**
     * @param  array{0: Money, 1: int}|null  $single
     */
    protected function writeOpeningLot(PartnerStockItem $item, int $units, ?array $single): void
    {
        $net = $single === null ? null : $this->lots->share($single[0], $single[1], 0, $units);

        $lot = PartnerStockCostLot::create([
            'business_account_id' => $item->business_account_id,
            'partner_stock_item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'provenance' => PartnerStockCostLot::PROVENANCE_OPENING,
            'purchased_quantity' => $units,
            'remaining_quantity' => $units,
            'currency_code' => $net?->currency->value ?? Currency::base()->value,
            'net_cost' => $net,
            'unit_cost' => $single === null ? null : $this->lots->share($single[0], $single[1], 0, 1),
            'cost_is_known' => $single !== null,
            // Older than anything bought from now on, so it is sold first.
            'purchased_at' => CarbonImmutable::parse('2000-01-01 00:00:00'),
        ]);

        PartnerStockLotMovement::create([
            'partner_stock_cost_lot_id' => $lot->id,
            'partner_stock_item_id' => $item->id,
            'type' => PartnerStockLotMovement::TYPE_ADJUSTED,
            'quantity' => $units,
            'currency_code' => $lot->currency_code,
            'cost' => $net,
            'idempotency_key' => 'lot-opening:'.$lot->id,
            'reason' => $single === null
                ? 'Units on the shelf when cost lots began; bought at more than one price, so their cost is unknown.'
                : 'Units on the shelf when cost lots began; every purchase was at one price.',
        ]);
    }
}
