<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Data\MovementContext;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockAllocation;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Domain\Inventory\StockAllocations;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A person giving an account's allocated stock back to everyone (§19, P3-30).
 *
 * The same authority as allocating — `inventory.approve`, at the controller and
 * here — with a reason, audited. Only units still set aside can be given back:
 * what one of the account's orders has already reserved belongs to that order
 * until it ends, and a reservation that ends returns its units to the allocation.
 */
class ReleaseAllocatedStock
{
    public function __construct(
        protected StockAllocations $allocations,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InventoryRefused when the allocation holds fewer units
     * @throws InvalidArgumentException when the quantity or reason is missing
     */
    public function handle(User $actor, StockAllocation $allocation, int $quantity, string $reason): StockAllocation
    {
        InventoryPolicy::authorize(InventoryPolicy::canApprove($actor));

        $reason = trim($reason);

        if (mb_strlen($reason) < AllocateStock::MINIMUM_REASON) {
            throw new InvalidArgumentException('Releasing an allocation needs a reason of at least '.AllocateStock::MINIMUM_REASON.' characters.');
        }

        return DB::transaction(function () use ($actor, $allocation, $quantity, $reason) {
            // The item's row lock first, as every allocation path takes it.
            /** @var StockItem $item */
            $item = StockItem::query()->lockForUpdate()->findOrFail($allocation->stock_item_id);
            $before = $item->buckets();
            $held = (int) StockAllocation::query()->whereKey($allocation->id)->value('quantity');

            $released = $this->allocations->release($allocation, $quantity, new MovementContext(
                reason: $reason,
                actorId: $actor->id,
            ));

            $this->audit->handle(new AuditEntry(
                action: 'inventory.allocation_released',
                actorId: $actor->id,
                auditableType: StockItem::class,
                auditableId: $item->id,
                before: ['buckets' => $before, 'allocation' => $held],
                after: ['buckets' => $item->refresh()->buckets(), 'allocation' => $released->quantity],
                reason: $reason,
                note: $released->account->public_id.': -'.$quantity,
                accountId: $released->business_account_id,
                module: 'inventory',
                isSensitive: true,
            ));

            return $released;
        });
    }
}
