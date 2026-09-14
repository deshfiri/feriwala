<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductStatusChange;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Inventory\Actions\RespondToStockChange;
use App\Models\User;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Move a product through its lifecycle (§11.2).
 *
 * Four things are checked, in this order, and every one of them here rather than
 * only in the controller — a bulk action (P3-13) reaches this class too:
 *
 *   1. **the permission for this particular move.** Writing a product and
 *      putting it in front of partners are different decisions (§12): activating
 *      needs `catalog.publish`, archiving `catalog.archive`, taking a live
 *      product off sale `catalog.unpublish`;
 *   2. **the move itself**, declared by {@see ProductStatus};
 *   3. **a reason**, where the move retires the product;
 *   4. **readiness**, for activation: a product nobody can place or price is
 *      not activated, whoever asks.
 *
 * The product row is locked, the history row is written in the same
 * transaction, and `published_at` is stamped the first time the product goes
 * live and never again.
 */
class TransitionProduct
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CatalogRefused
     * @throws IllegalStateTransition
     */
    public function handle(User $actor, Product $product, ProductStatus $to, ?string $reason = null): Product
    {
        if ($to->axis() !== ProductStatus::AXIS_LIFECYCLE) {
            throw CatalogRefused::notLifecycleStatus($to->label());
        }

        return $this->database->transaction(function () use ($actor, $product, $to, $reason) {
            /** @var Product $locked */
            $locked = Product::query()
                ->with(['category.parent', 'brand', 'variants'])
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->status;

            if (! CatalogPolicy::canMoveProduct($actor, $from, $to)) {
                throw new AuthorizationException('You may not move a product from '.$from->label().' to '.$to->label().'.');
            }

            if ($to->requiresReason() && blank($reason)) {
                throw CatalogRefused::reasonRequired($to->label());
            }

            if ($to === ProductStatus::Active) {
                $this->assertReadyToActivate($locked);
            }

            $locked->transitionTo($to);

            if ($to === ProductStatus::Active && $locked->published_at === null) {
                $locked->published_at = now()->toImmutable();
            }

            $locked->save();

            ProductStatusChange::create([
                'product_id' => $locked->id,
                'axis' => ProductStatus::AXIS_LIFECYCLE,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'reason' => $reason,
            ]);

            /*
             * A product put on sale with none of its tracked stock available
             * comes straight back off it once this commits (§19: out-of-stock
             * protection). A product whose stock is not tracked is left alone.
             */
            if ($to === ProductStatus::Active) {
                $productId = $locked->id;

                $this->database->connection()->afterCommit(
                    fn () => app(RespondToStockChange::class)->handle($productId),
                );
            }

            $this->audit->handle(new AuditEntry(
                action: 'catalog.product_status_changed',
                actorId: $actor->id,
                auditableType: Product::class,
                auditableId: $locked->id,
                before: ['status' => $from->value],
                after: ['status' => $to->value, 'reason' => $reason],
                module: 'catalog',
            ));

            return $locked;
        });
    }

    /**
     * What a product needs before any partner may be offered it.
     *
     * @throws CatalogRefused
     */
    protected function assertReadyToActivate(Product $product): void
    {
        $missing = [];

        if (! $product->category->isAvailable()) {
            $missing[] = 'its category is switched off';
        }

        if ($product->brand !== null && ! $product->brand->is_active) {
            $missing[] = 'its brand is switched off';
        }

        if (! $product->wholesale_price_minor->isPositive()) {
            $missing[] = 'it has no wholesale price';
        }

        if ($product->variants->isNotEmpty() && $product->variants->where('is_active', true)->isEmpty()) {
            $missing[] = 'every one of its variations is switched off';
        }

        if ($missing !== []) {
            throw CatalogRefused::cannotActivate($missing);
        }
    }
}
