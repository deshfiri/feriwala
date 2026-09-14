<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Decide when a SKU in a warehouse counts as running low (§19, P3-29).
 *
 * `inventory.edit`, like every other change to how stock is held. Empty means
 * nobody is told. A new threshold starts a fresh watch, and is checked at once:
 * setting it above what the warehouse already holds is itself news somebody
 * should hear about.
 */
class SetLowStockThreshold
{
    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException when the threshold is negative
     */
    public function handle(User $actor, StockItem $item, ?int $threshold): StockItem
    {
        InventoryPolicy::authorize(InventoryPolicy::canEdit($actor));

        if ($threshold !== null && $threshold < 0) {
            throw new InvalidArgumentException('A low-stock threshold cannot be negative.');
        }

        return DB::transaction(function () use ($actor, $item, $threshold) {
            /** @var StockItem $locked */
            $locked = StockItem::query()->lockForUpdate()->findOrFail($item->id);
            $before = $locked->low_stock_threshold;

            DB::table('stock_items')->where('id', $locked->id)->update([
                'low_stock_threshold' => $threshold,
                'low_stock_alerted_at' => null,
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'inventory.low_stock_threshold_set',
                actorId: $actor->id,
                auditableType: StockItem::class,
                auditableId: $locked->id,
                before: ['low_stock_threshold' => $before],
                after: ['low_stock_threshold' => $threshold],
                module: 'inventory',
            ));

            $itemId = $locked->id;

            DB::afterCommit(fn () => app(CheckLowStock::class)->handle($itemId));

            return $locked->refresh();
        });
    }
}
