<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatusChange;
use App\Domain\Inventory\Queries\StockAvailability;
use Illuminate\Database\DatabaseManager;

/**
 * Take a product off sale when its central stock runs out, and put it back when
 * stock returns (§19: out-of-stock protection, §11.2).
 *
 * `ProductStatus` has always said Out of Stock is "set by hand today; the
 * inventory tasks will drive it". This is that: a product nobody can supply is
 * not offered to any partner — partner catalogues only offer Active products —
 * so it cannot be ordered into a shortfall.
 *
 * Deliberately narrow:
 *
 *   - **Only products whose stock is tracked.** A product with no stock held
 *     anywhere yet has not been brought into inventory, and hiding it would be a
 *     guess, not protection.
 *   - **Only between Active and Out of Stock.** A draft stays a draft, and an
 *     inactive, discontinued or archived product is not put on sale by stock
 *     arriving.
 *   - **Never over a person's decision.** Out of Stock is put back to Active only
 *     when this class is what moved it there. A product somebody took off sale by
 *     hand stays off sale however much stock arrives.
 *
 * Each move is written to the status history with no actor, and to the audit log
 * as a system action, in the same transaction, under the product row lock.
 */
class SyncProductStockStatus
{
    /** Stored as translation keys, so the history reads in its reader's language. */
    public const RAN_OUT = 'inventory.status_sync.ran_out';

    public const RESTOCKED = 'inventory.status_sync.restocked';

    public function __construct(
        protected StockAvailability $availability,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return ProductStatus|null the status the product was moved to, or null when nothing moved
     */
    public function handle(int $productId): ?ProductStatus
    {
        return $this->database->transaction(function () use ($productId) {
            /** @var Product|null $product */
            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();

            if ($product === null || ! $this->availability->productIsTracked($product)) {
                return null;
            }

            $from = $product->status;
            $hasStock = $this->availability->productHasStock($product);

            $to = match (true) {
                $from === ProductStatus::Active && ! $hasStock => ProductStatus::OutOfStock,
                $from === ProductStatus::OutOfStock && $hasStock && $this->ranOutBySystem($product) => ProductStatus::Active,
                default => null,
            };

            if ($to === null) {
                return null;
            }

            $reason = $to === ProductStatus::OutOfStock ? self::RAN_OUT : self::RESTOCKED;

            $product->transitionTo($to);
            $product->save();

            ProductStatusChange::create([
                'product_id' => $product->id,
                'axis' => ProductStatus::AXIS_LIFECYCLE,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => null,
                'reason' => $reason,
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'catalog.product_status_changed',
                actorType: 'system',
                auditableType: Product::class,
                auditableId: $product->id,
                before: ['status' => $from->value],
                after: ['status' => $to->value, 'reason' => $reason],
                module: 'inventory',
            ));

            return $to;
        });
    }

    /**
     * Whether the product's last lifecycle move — into Out of Stock — was this
     * class's rather than a person's.
     */
    protected function ranOutBySystem(Product $product): bool
    {
        /** @var ProductStatusChange|null $last */
        $last = ProductStatusChange::query()
            ->where('product_id', $product->id)
            ->where('axis', ProductStatus::AXIS_LIFECYCLE)
            ->orderByDesc('id')
            ->first();

        return $last !== null
            && $last->to_status === ProductStatus::OutOfStock
            && $last->actor_id === null;
    }
}
