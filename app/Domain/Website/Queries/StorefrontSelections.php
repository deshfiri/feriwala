<?php

namespace App\Domain\Website\Queries;

use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a website may show its customers, stated once (contract §5.1, D30, P2 H9).
 *
 * A selection is on sale when it is **published on this website** and its
 * product is **eligible for this website's owner to sell by dropshipping** —
 * the same eligibility order pricing applies ({@see ProductEligibility}), so a
 * product listed here is never refused at checkout for being ineligible, and
 * a trashed, inactive, switched-off or channel-disabled product is never
 * listed. Every storefront catalogue read — list, detail, stock, facets —
 * starts here.
 *
 * Scoped to the website before anything else; nothing here takes a website
 * from anywhere but the caller, which takes it from the credential.
 */
class StorefrontSelections
{
    public function __construct(
        protected ProductEligibility $eligibility,
    ) {}

    /**
     * This website's selections that are on sale.
     *
     * @return Builder<WebsiteProduct>
     */
    public function query(Website $website): Builder
    {
        return WebsiteProduct::query()
            ->where('website_products.website_id', $website->id)
            ->where('website_products.status', WebsiteProductStatus::Published->value)
            ->whereIn('website_products.product_id', $this->eligibility
                ->query($website->businessAccount, SalesChannel::Dropshipping)
                ->select('products.id'));
    }
}
