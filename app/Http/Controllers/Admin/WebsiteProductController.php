<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\ResolvesWebsiteForStaff;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Catalog\PurchasedProducts;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Http\Controllers\Erp\WebsiteProductController as PartnerWebsiteProductController;
use Illuminate\Http\Request;

/**
 * The products a partner's storefront sells, chosen and priced by platform
 * staff on their behalf.
 *
 * The partner's controller, unchanged — selection is still never authorship, a
 * price is still held to the bounds an administrator set, and the publish limit
 * still counts — plus one thing the partner has no need of here: a way to find a
 * product to add. A partner picks from the catalogue's own pages; staff have no
 * such page for someone else's shop, so the products page carries a search.
 * It offers only what the partner could have chosen themselves
 * ({@see ProductEligibility} and {@see PurchasedProducts}), so
 * staff cannot sell a partner something the partner is not entitled to.
 */
class WebsiteProductController extends PartnerWebsiteProductController
{
    use ResolvesWebsiteForStaff;

    /** How many matches the picker lists. */
    protected const CANDIDATES = 10;

    /**
     * @return array<string, mixed>
     */
    protected function extraIndexProps(Request $request, Website $website): array
    {
        $term = mb_substr(trim($request->string('find')->toString()), 0, 100);

        return [
            'find' => $term,
            'candidates' => mb_strlen($term) < 2 ? [] : $this->candidates($website, $term),
        ];
    }

    /**
     * Eligible catalogue products matching the search, each marked when this
     * storefront already sells it.
     *
     * @return list<array{id: string, name: string, sku: string|null, image: string|null, selected: bool}>
     */
    protected function candidates(Website $website, string $term): array
    {
        $selected = WebsiteProduct::query()
            ->where('website_id', $website->id)
            ->pluck('product_id')
            ->all();

        $media = app(ProductMediaStore::class);

        // What the partner may sell: eligible in the channel, and — unless the
        // account sells straight from the catalogue — bought. The same two
        // questions the add step asks, so every match here can be added.
        $sellable = app(PurchasedProducts::class)->onlySellable(
            $this->eligibility->query($website->businessAccount, SalesChannel::Dropshipping),
            $website->businessAccount,
        );

        $matches = $sellable
            ->where(fn ($query) => $query
                ->where('name', 'ilike', "%{$term}%")
                ->orWhere('sku', 'ilike', "%{$term}%"))
            ->with(['media' => fn ($query) => $query->where('type', ProductMedia::TYPE_IMAGE)->orderBy('position')])
            ->orderBy('name')
            ->limit(self::CANDIDATES)
            ->get();

        $candidates = [];

        foreach ($matches as $product) {
            $image = $product->media->first();

            $candidates[] = [
                'id' => $product->public_id,
                'name' => $product->name,
                'sku' => $product->sku,
                'image' => $image === null ? null : $media->url($image->path),
                'selected' => in_array($product->id, $selected, true),
            ];
        }

        return $candidates;
    }
}
