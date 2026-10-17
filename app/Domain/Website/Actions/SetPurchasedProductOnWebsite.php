<?php

namespace App\Domain\Website\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Models\User;

/**
 * From My Products: put a product the Partner bought on one of their storefronts,
 * or take it off (D34).
 *
 * Buying a product only makes it eligible; it reaches a storefront because the
 * owner chose it for **that** storefront. So this selects it there when it is not
 * selected yet — {@see SelectProductForWebsite} still asks whether it was bought
 * and is still eligible — and then publishes it, with every check publishing has
 * (a price, units left to sell, the package limit, KYC).
 *
 * Taking it off only unpublishes: the selection stays, with its price and its
 * history, so putting it back is one click and nothing a customer ordered loses
 * the line it came from. The purchase, the stock and the sales are untouched.
 */
class SetPurchasedProductOnWebsite
{
    public function __construct(
        protected SelectProductForWebsite $select,
        protected SetWebsiteProductPublication $publication,
    ) {}

    /**
     * @throws WebsiteRefused
     */
    public function handle(Website $website, Product $product, User $actor, bool $published): ?WebsiteProduct
    {
        /** @var WebsiteProduct|null $selection */
        $selection = WebsiteProduct::query()
            ->where('website_id', $website->id)
            ->where('product_id', $product->id)
            ->first();

        if (! $published) {
            return $selection === null ? null : $this->publication->unpublish($selection, $actor);
        }

        $selection ??= $this->select->handle($website, $product, $actor);

        $selection->setRelation('website', $website);
        $selection->setRelation('product', $product);

        return $this->publication->publish($selection, $actor);
    }
}
