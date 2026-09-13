<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * File a product under a category, or give it a brand (§11.3 "Assign Products").
 *
 * Placement is authoring, so it asks `catalog.edit`, here as well as at the
 * controller. The product row is locked and the category or brand it is being
 * given is read under a share lock, so a category deleted at the same moment is
 * either still there when the product lands in it or refused by its own delete
 * — never a product pointing at nothing.
 *
 * Nothing about what was sold changes: orders will record what they were placed
 * against, and a product moved into a switched-off category simply stops being
 * offered until the category is switched back on.
 */
class AssignProducts
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function toCategory(User $actor, Product $product, Category $category): Product
    {
        return $this->assign($actor, $product, 'category_id', $category->id, function () use ($category) {
            Category::query()->whereKey($category->id)->sharedLock()->firstOrFail();
        });
    }

    /**
     * Give the product this brand, or no brand at all.
     *
     * @throws AuthorizationException
     */
    public function toBrand(User $actor, Product $product, ?Brand $brand): Product
    {
        return $this->assign($actor, $product, 'brand_id', $brand?->id, function () use ($brand) {
            if ($brand !== null) {
                Brand::query()->whereKey($brand->id)->sharedLock()->firstOrFail();
            }
        });
    }

    /**
     * @param  callable(): void  $lockTarget
     *
     * @throws AuthorizationException
     */
    protected function assign(User $actor, Product $product, string $column, ?int $id, callable $lockTarget): Product
    {
        if (! CatalogPolicy::canEdit($actor)) {
            throw new AuthorizationException('You may not change where products are filed.');
        }

        return $this->database->transaction(function () use ($actor, $product, $column, $id, $lockTarget) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $before = $locked->getAttribute($column);

            if ($before === $id) {
                return $locked;
            }

            $lockTarget();

            $locked->forceFill([$column => $id])->save();

            $this->audit->handle(new AuditEntry(
                action: $column === 'category_id' ? 'catalog.product_category_assigned' : 'catalog.product_brand_assigned',
                actorId: $actor->id,
                auditableType: Product::class,
                auditableId: $locked->id,
                before: [$column => $before],
                after: [$column => $id],
                module: 'catalog',
            ));

            return $locked;
        });
    }
}
