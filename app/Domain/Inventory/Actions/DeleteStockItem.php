<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Stop holding a SKU in a warehouse, for a row that was never used (§19).
 *
 * The movement history is append-only, so a row that has one — or a reservation,
 * an allocation or an order line — is part of the ledger and is refused. What can
 * go is a row tracked by mistake: every bucket at zero and nothing ever recorded
 * against it. The check is made under the row lock, in the same transaction as
 * the delete, so a movement cannot slip in between.
 */
class DeleteStockItem
{
    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InventoryRefused when the row has any history
     */
    public function handle(User $actor, StockItem $item): void
    {
        InventoryPolicy::authorize(InventoryPolicy::canEdit($actor));

        DB::transaction(function () use ($actor, $item) {
            /** @var StockItem $locked */
            $locked = StockItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($locked->hasHistory()) {
                throw InventoryRefused::stockHasHistory();
            }

            $before = [
                'warehouse' => $locked->warehouse->code,
                'sku' => $locked->sku(),
            ];
            $productId = $locked->product_id;

            $locked->delete();

            $this->audit->handle(new AuditEntry(
                action: 'inventory.stock_deleted',
                actorId: $actor->id,
                auditableType: StockItem::class,
                auditableId: $locked->id,
                before: $before,
                module: 'inventory',
            ));

            if ($productId !== null) {
                DB::afterCommit(fn () => app(RespondToStockChange::class)->handle($productId));
            }
        });
    }
}
