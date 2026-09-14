<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Account\Models\BusinessAccount;
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
 * A person setting central stock aside for one business account (§19:
 * user-allocated stock, P3-30).
 *
 * `inventory.approve`, asked here as well as at the controller: allocating
 * decides that one business gets stock ahead of every other, which is the same
 * kind of decision as overriding a reservation (P3-26), not a stock count. A
 * partner is refused whatever it has been handed. A reason every time, and the
 * change is audited with the allocation and every bucket before and after, in
 * the same transaction as the movement it makes.
 */
class AllocateStock
{
    public const MINIMUM_REASON = 10;

    public function __construct(
        protected StockAllocations $allocations,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InventoryRefused when the account cannot trade, the warehouse is off, or too few are available
     * @throws InvalidArgumentException when the quantity or reason is missing
     */
    public function handle(User $actor, StockItem $item, BusinessAccount $account, int $quantity, string $reason): StockAllocation
    {
        InventoryPolicy::authorize(InventoryPolicy::canApprove($actor));

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MINIMUM_REASON) {
            throw new InvalidArgumentException('An allocation needs a reason of at least '.self::MINIMUM_REASON.' characters.');
        }

        return DB::transaction(function () use ($actor, $item, $account, $quantity, $reason) {
            // The item's row lock first, so the figures audited as "before" are the
            // ones the allocation actually starts from.
            $before = StockItem::query()->lockForUpdate()->findOrFail($item->id)->buckets();
            $held = (int) StockAllocation::query()
                ->where('stock_item_id', $item->id)
                ->where('business_account_id', $account->id)
                ->value('quantity');

            $allocation = $this->allocations->allocate($item, $account, $quantity, new MovementContext(
                reason: $reason,
                actorId: $actor->id,
            ));

            $this->audit->handle(new AuditEntry(
                action: 'inventory.stock_allocated',
                actorId: $actor->id,
                auditableType: StockItem::class,
                auditableId: $item->id,
                before: ['buckets' => $before, 'allocation' => $held],
                after: ['buckets' => $item->buckets(), 'allocation' => $allocation->quantity],
                reason: $reason,
                note: $account->public_id.': +'.$quantity,
                accountId: $account->id,
                module: 'inventory',
                isSensitive: true,
            ));

            return $allocation;
        });
    }
}
