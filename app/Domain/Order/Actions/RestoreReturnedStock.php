<?php

namespace App\Domain\Order\Actions;

use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\PartnerStock;
use App\Domain\Inventory\StockLedger;
use App\Domain\Order\Data\ReturnedLine;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Supplier\Actions\ReverseSupplierPayable;
use App\Domain\Supplier\Models\SupplierOfferStock;
use App\Domain\Supplier\SupplierStockLedger;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Put the units of one returned line where the person who judged them said
 * (§19, §19.1, §20, P6-12, D35).
 *
 * The one place a returned unit changes buckets, whichever flow brought it
 * here: the original receipt, which counts and judges goods in one step, and the
 * case workflow's inspection, which only does so once a person has approved the
 * goods. Stock moves through the stock ledgers like every other change — never
 * by writing a figure — so each movement carries who, why and what the figures
 * were before and after.
 *
 * **Once, and only once.** The movement that restored a line is written onto
 * that line, and the column is unique; the ledger's own idempotency key says the
 * same thing from the other side. A line already restored is left alone.
 *
 * A line is restored into the stock it left: the Partner's own units if the
 * order was filled from them (D34), a Supplier offer's stock if the line was
 * allocated to one (D25, P13-22) — in which case its Supplier payable is
 * reversed for the same quantity — and the warehouse's otherwise. A profit
 * already credited on the returned units is taken back out of the kind of money
 * it was credited as.
 */
class RestoreReturnedStock
{
    public function __construct(
        protected StockLedger $ledger,
        protected PartnerStock $partnerStock,
        protected SupplierStockLedger $supplierLedger,
        protected ReverseSupplierPayable $supplierPayables,
        protected ReverseOrderProceedsSettlement $proceeds,
    ) {}

    /**
     * @throws ReturnRefused
     */
    public function handle(OrderReturnItem $item, ReturnedLine $line, Warehouse $warehouse, User $actor): void
    {
        if ($item->isRestored()) {
            // Already taken in under another request; its movement stands.
            return;
        }

        $item->forceFill([
            'received_quantity' => $line->quantity,
            'disposition' => $line->disposition,
            'warehouse_id' => $warehouse->id,
        ])->save();

        if ($line->quantity === 0) {
            // Nothing arrived for this line. Recorded, with nothing to move.
            return;
        }

        // A profit already credited on the returned units is taken back, out of
        // the kind of money it was credited as. Never fails the return itself.
        $this->proceeds->forReturn($item);

        $orderItem = $item->orderItem;

        if ($orderItem->stockReservation?->isPartnerSourced() === true) {
            $item->forceFill([
                'partner_stock_movement_id' => $this->restoreToPartner($item, $line, $actor),
                'restored_at' => CarbonImmutable::now(),
            ])->save();

            return;
        }

        if ($orderItem->isSupplierBacked()) {
            $movementId = $this->restoreToSupplier($item, $line, $actor);

            $item->forceFill([
                'supplier_stock_movement_id' => $movementId,
                'restored_at' => CarbonImmutable::now(),
            ])->save();

            $this->supplierPayables->handle(
                $item,
                $line->quantity,
                'Returned on '.$item->orderReturn->reference.': '.$line->disposition->label().'.',
            );

            return;
        }

        $movement = $this->restore($item, $line, $warehouse, $actor);

        $item->forceFill([
            'stock_movement_id' => $movement,
            'restored_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * Move the units out of sold stock and into what they now are.
     *
     * @throws ReturnRefused
     */
    protected function restore(OrderReturnItem $item, ReturnedLine $line, Warehouse $warehouse, User $actor): int
    {
        $orderItem = $item->orderItem;

        /** @var StockItem $stock */
        $stock = StockItem::query()->firstOrCreate([
            'warehouse_id' => $warehouse->id,
            'product_id' => $orderItem->product_id,
            'product_variant_id' => $orderItem->product_variant_id,
        ]);

        try {
            $movement = $this->ledger->move(
                $stock,
                StockBucket::Sold,
                $line->disposition->bucket(),
                $line->quantity,
                StockMovementType::Adjustment,
                new MovementContext(
                    reason: 'Returned on '.$item->orderReturn->reference.': '.$line->disposition->label().'.',
                    actorId: $actor->id,
                    sourceType: 'order_return',
                    sourceId: $item->orderReturn->id,
                    // One restoration per returned line, whatever arrives twice.
                    idempotencyKey: 'order-return-item:'.$item->public_id,
                ),
            );
        } catch (InventoryRefused) {
            throw ReturnRefused::nothingSoldToRestore($orderItem->sku);
        }

        return $movement->id;
    }

    /**
     * The Partner-stock twin of {@see restore()}: a storefront line was sold from
     * the Partner's own units, so its goods go back into that Partner's stock and
     * no one else's (D34).
     *
     * @throws ReturnRefused
     */
    protected function restoreToPartner(OrderReturnItem $item, ReturnedLine $line, User $actor): int
    {
        $orderItem = $item->orderItem;
        $stock = $orderItem->stockReservation?->partnerStock;

        if ($stock === null) {
            throw ReturnRefused::nothingSoldToRestore($orderItem->sku);
        }

        try {
            $movement = $this->partnerStock->move(
                $stock,
                StockBucket::Sold,
                $line->disposition->bucket(),
                $line->quantity,
                PartnerStockMovementType::ReturnReceived,
                new MovementContext(
                    reason: 'Returned on '.$item->orderReturn->reference.': '.$line->disposition->label().'.',
                    actorId: $actor->id,
                    sourceType: 'order_return',
                    sourceId: $item->orderReturn->id,
                    idempotencyKey: 'order-return-item:'.$item->public_id,
                ),
            );
        } catch (InventoryRefused) {
            throw ReturnRefused::nothingSoldToRestore($orderItem->sku);
        }

        return $movement->id;
    }

    /**
     * The Supplier-stock twin of {@see restore()}: the same "sold" bucket
     * discipline, on the offer's own stock rather than a warehouse (D25,
     * P13-22).
     *
     * @throws ReturnRefused
     */
    protected function restoreToSupplier(OrderReturnItem $item, ReturnedLine $line, User $actor): int
    {
        $orderItem = $item->orderItem;

        /** @var SupplierOfferStock $stock */
        $stock = SupplierOfferStock::query()->firstOrCreate(
            ['supplier_offer_id' => $orderItem->supplier_offer_id],
            ['quantity' => 0],
        );

        try {
            $movement = $this->supplierLedger->move(
                $stock,
                StockBucket::Sold,
                $line->disposition->bucket(),
                $line->quantity,
                'return_received',
                reason: 'Returned on '.$item->orderReturn->reference.': '.$line->disposition->label().'.',
                idempotencyKey: 'order-return-item:'.$item->public_id,
            );
        } catch (InventoryRefused) {
            throw ReturnRefused::nothingSoldToRestore($orderItem->sku);
        }

        return $movement->id;
    }
}
