<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * Choose which products a product recommends, and in what order (§11.1).
 *
 * The whole list in one locked write, positions renumbered 1..n. A related
 * product can be in any status — an administrator may line up a recommendation
 * before the recommended product goes live — because what a partner is shown is
 * filtered by eligibility when the page is rendered, never here.
 */
class SetRelatedProducts
{
    /**
     * A handful: a list of recommendations nobody scrolls is not a
     * recommendation.
     */
    public const MAX_RELATED = 12;

    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<int, string>  $publicIds  in the order wanted
     *
     * @throws CatalogRefused
     */
    public function handle(User $actor, Product $product, array $publicIds): void
    {
        CatalogPolicy::authorize(CatalogPolicy::canEdit($actor), 'You may not change related products.');

        $publicIds = array_values(array_unique($publicIds));

        if (count($publicIds) > self::MAX_RELATED) {
            throw CatalogRefused::tooManyRelated(self::MAX_RELATED);
        }

        if (in_array($product->public_id, $publicIds, true)) {
            throw CatalogRefused::relatedToItself();
        }

        $this->database->transaction(function () use ($actor, $product, $publicIds) {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            $before = $locked->relatedProducts()->pluck('products.public_id')->all();

            $ids = Product::query()->whereIn('public_id', $publicIds)->pluck('id', 'public_id');

            $sync = [];
            $position = 1;

            foreach ($publicIds as $publicId) {
                if (isset($ids[$publicId])) {
                    $sync[$ids[$publicId]] = ['position' => $position++];
                }
            }

            $locked->relatedProducts()->sync($sync);

            $this->audit->handle(new AuditEntry(
                action: 'catalog.related_products_set',
                actorId: $actor->id,
                auditableType: Product::class,
                auditableId: $locked->id,
                before: ['related' => $before],
                after: ['related' => array_keys(array_filter(
                    array_flip($publicIds),
                    fn (int $index, string $publicId) => isset($ids[$publicId]),
                    ARRAY_FILTER_USE_BOTH,
                ))],
                module: 'catalog',
            ));
        });
    }
}
