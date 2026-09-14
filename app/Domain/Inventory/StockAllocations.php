<?php

namespace App\Domain\Inventory;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockAllocation;
use App\Domain\Inventory\Models\StockItem;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Setting central stock aside for one business account, and giving it back
 * (§19: user-allocated stock, P3-30).
 *
 * Allocating moves units from available to allocated through the stock ledger,
 * so the movement is written with it and nobody else's order or website can
 * reach them; releasing moves them back. The account's allocation row says whose
 * they are, and changes in the same transaction as the figure.
 *
 * **Lock order is always the stock item, then the allocation.** Every path that
 * touches an allocation — this class, and a reservation drawing on or returning
 * to one — takes the item's row lock first, so no two of them can wait on each
 * other, and the allocation is re-read under that lock before it is trusted.
 *
 * No authorisation and no audit here: those are the actions' (AllocateStock,
 * ReleaseAllocatedStock), exactly as the reservation service leaves them to
 * OverrideReservation. This is the mechanism.
 */
class StockAllocations
{
    public function __construct(
        protected StockLedger $ledger,
        protected DatabaseManager $database,
    ) {}

    /**
     * Set `$quantity` available units of this item aside for `$account`.
     *
     * @throws InventoryRefused when the account cannot trade, the warehouse is
     *                          switched off, or fewer units are available
     */
    public function allocate(StockItem $item, BusinessAccount $account, int $quantity, MovementContext $context = new MovementContext): StockAllocation
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('An allocation sets aside at least one unit.');
        }

        if (! $account->canTransact()) {
            throw InventoryRefused::accountCannotHoldStock($account->name);
        }

        return $this->database->transaction(function () use ($item, $account, $quantity, $context) {
            /** @var StockItem $locked */
            $locked = StockItem::query()->with('warehouse')->lockForUpdate()->findOrFail($item->id);

            if (! $locked->warehouse->is_active) {
                throw InventoryRefused::warehouseInactive();
            }

            // Under the item lock, so two first allocations cannot both create the row.
            /** @var StockAllocation $allocation */
            $allocation = StockAllocation::query()->firstOrCreate(
                ['stock_item_id' => $locked->id, 'business_account_id' => $account->id],
                ['quantity' => 0],
            );

            $this->ledger->move(
                $locked,
                StockBucket::Available,
                StockBucket::Allocated,
                $quantity,
                StockMovementType::Allocation,
                new MovementContext(
                    reason: $context->reason,
                    actorId: $context->actorId,
                    sourceType: 'stock_allocation',
                    sourceId: $allocation->id,
                    idempotencyKey: $context->idempotencyKey,
                ),
            );

            $allocation->forceFill(['quantity' => $allocation->quantity + $quantity])->save();

            $item->setRawAttributes($locked->getAttributes(), sync: true);

            return $allocation;
        });
    }

    /**
     * Give `$quantity` of an account's allocated units back to available.
     *
     * @throws InventoryRefused when the allocation holds fewer units
     */
    public function release(StockAllocation $allocation, int $quantity, MovementContext $context = new MovementContext): StockAllocation
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Releasing an allocation gives back at least one unit.');
        }

        return $this->database->transaction(function () use ($allocation, $quantity, $context) {
            /** @var StockItem $item */
            $item = StockItem::query()->lockForUpdate()->findOrFail($allocation->stock_item_id);

            /** @var StockAllocation $locked */
            $locked = StockAllocation::query()->lockForUpdate()->findOrFail($allocation->id);

            if ($locked->quantity < $quantity) {
                throw InventoryRefused::allocationInsufficient($locked->quantity, $quantity);
            }

            $this->ledger->move(
                $item,
                StockBucket::Allocated,
                StockBucket::Available,
                $quantity,
                StockMovementType::AllocationReleased,
                new MovementContext(
                    reason: $context->reason,
                    actorId: $context->actorId,
                    sourceType: 'stock_allocation',
                    sourceId: $locked->id,
                    idempotencyKey: $context->idempotencyKey,
                ),
            );

            $locked->forceFill(['quantity' => $locked->quantity - $quantity])->save();

            $allocation->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        });
    }
}
