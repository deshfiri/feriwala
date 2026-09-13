<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Feature a product, or stop featuring it (§11.1).
 *
 * Featuring puts a product in front of every eligible partner ahead of the rest
 * of the catalogue, which is a publishing decision — so it asks
 * `catalog.publish`, here as well as at the controller. `featured_at` is stamped
 * each time a product is featured, and cleared when it stops being.
 */
class SetFeatured
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, Product $product, bool $featured): Product
    {
        if (! CatalogPolicy::canPublish($actor)) {
            throw new AuthorizationException('You may not change which products are featured.');
        }

        return $this->database->transaction(function () use ($actor, $product, $featured) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_featured === $featured) {
                return $locked;
            }

            $locked->forceFill([
                'is_featured' => $featured,
                'featured_at' => $featured ? now() : null,
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: $featured ? 'catalog.product_featured' : 'catalog.product_unfeatured',
                actorId: $actor->id,
                auditableType: Product::class,
                auditableId: $locked->id,
                before: ['is_featured' => ! $featured],
                after: ['is_featured' => $featured],
                module: 'catalog',
            ));

            return $locked;
        });
    }
}
