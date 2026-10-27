<?php

namespace App\Domain\Damage\Actions;

use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\PartnerStock;
use App\Domain\Inventory\StockLedger;
use App\Models\User;

/**
 * Move the units of a damage case between stock buckets — the one place a
 * damaged unit changes buckets (D35).
 *
 * Stock moves through the stock ledgers like every other change, never by
 * writing a figure, so each movement carries who, why and what the figures were
 * before and after. Units are moved in the stock they belong to: the Partner's
 * own if the line was sold from it (D34), the warehouse's otherwise.
 *
 * **Once, and only once.** The idempotency key names the case and what it is
 * for, and the ledger refuses a second movement under the same key by returning
 * the first, so a retry or a racing request moves nothing twice. A damaged unit
 * is never moved *into* the available bucket by anything but an inspector's
 * explicit decision to restock it.
 */
class MoveDamageStock
{
    public function __construct(
        protected StockLedger $ledger,
        protected PartnerStock $partnerStock,
    ) {}

    /**
     * @param  string  $purpose  what the movement is for, e.g. `intake`, `restock`, `dispatch`; part of its key
     * @return array{stock_movement_id: int|null, partner_stock_movement_id: int|null}
     *
     * @throws DamageRefused
     */
    public function handle(OrderDamage $damage, ?StockBucket $from, ?StockBucket $to, int $quantity, string $purpose, string $reason, User $actor): array
    {
        $item = $damage->orderItem;

        if ($item->isSupplierBacked()) {
            throw DamageRefused::supplierBacked();
        }

        $context = new MovementContext(
            reason: $reason,
            actorId: $actor->id,
            sourceType: 'order_damage',
            sourceId: $damage->id,
            idempotencyKey: 'order-damage:'.$damage->public_id.':'.$purpose,
        );

        try {
            if ($item->stockReservation?->isPartnerSourced() === true && $item->stockReservation->partnerStock !== null) {
                $movement = $this->partnerStock->move(
                    $item->stockReservation->partnerStock, $from, $to, $quantity, PartnerStockMovementType::Adjustment, $context,
                );

                return ['stock_movement_id' => null, 'partner_stock_movement_id' => $movement->id];
            }

            if ($damage->warehouse_id === null) {
                throw DamageRefused::needsAWarehouse();
            }

            /** @var StockItem $stock */
            $stock = StockItem::query()->firstOrCreate([
                'warehouse_id' => $damage->warehouse_id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
            ]);

            $movement = $this->ledger->move($stock, $from, $to, $quantity, StockMovementType::Adjustment, $context);

            return ['stock_movement_id' => $movement->id, 'partner_stock_movement_id' => null];
        } catch (InventoryRefused) {
            throw DamageRefused::nothingToRestore($item->sku);
        }
    }
}
