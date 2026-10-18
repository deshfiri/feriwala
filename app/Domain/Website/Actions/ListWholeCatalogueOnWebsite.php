<?php

namespace App\Domain\Website\Actions;

use App\Domain\Account\Enums\AccountType;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Kyc\KycRestrictions;
use App\Domain\Package\Entitlements;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Queries\ResolveWebsitePriceRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Put the whole eligible catalogue on a Non-Conditional account's storefront, at
 * each product's suggested price.
 *
 * This is the default for such an account: it sells without having bought, so
 * nothing needs choosing for a product to appear. The owner narrows it by
 * switching the shop to "selected only" ({@see SetWebsiteCatalogueMode}).
 *
 * Idempotent, and it only ever adds. A product is listed once per shop: one that
 * already has a row — chosen, hidden by the owner, or listed before — is left as
 * it is, so a product the owner took off is not put back. Rows it creates are
 * marked `auto_listed`, which keeps them out of the package publish limit and
 * lets a switch to "selected only" take them off again without touching what the
 * owner chose. A product with no suggested price, or one outside its own bounds,
 * is not listed: nothing goes on sale at a figure nobody set.
 *
 * Stock is not a condition. A product is listed while it is eligible and priced;
 * what a customer may buy is decided at checkout from the stock then.
 */
class ListWholeCatalogueOnWebsite
{
    protected const CHUNK = 200;

    public function __construct(
        protected ProductEligibility $eligibility,
        protected ResolveWebsitePriceRule $pricing,
        protected Entitlements $entitlements,
        protected KycRestrictions $kycRestrictions,
    ) {}

    /**
     * Whether this storefront lists the whole catalogue now.
     */
    public function applies(Website $website): bool
    {
        $account = $website->businessAccount;

        return $website->lists_whole_catalogue
            && $account->isNonConditional()
            && ($website->status->acceptsSync() || $website->status->isBeingBuilt())
            && ! $this->kycRestrictions->blocksPublishing($account);
    }

    /**
     * @return int how many products this call put on sale
     */
    public function handle(Website $website): int
    {
        if (! $this->applies($website)) {
            return 0;
        }

        $account = $website->businessAccount;
        $package = $this->entitlements->activePackage($account)?->package;
        $listed = $this->relist($website);

        $this->eligibility->query($account, SalesChannel::Dropshipping)
            ->select('products.*')
            ->whereNotIn('products.id', WebsiteProduct::query()->select('product_id')->where('website_id', $website->id))
            ->chunkById(self::CHUNK, function (Collection $products) use ($website, $package, &$listed): void {
                /** @var Product $product */
                foreach ($products as $product) {
                    $terms = $this->pricing->for($product, $package);
                    $price = $terms->suggested;

                    if ($price === null || ! $price->isPositive() || $terms->refusalFor($price) !== null) {
                        continue;
                    }

                    try {
                        WebsiteProduct::create([
                            'website_id' => $website->id,
                            'business_account_id' => $website->business_account_id,
                            'product_id' => $product->id,
                            'status' => WebsiteProductStatus::Published,
                            'sync_status' => WebsiteSyncStatus::Pending,
                            'currency_code' => $website->currency_code,
                            'price' => $price,
                            'auto_listed' => true,
                            'published_at' => CarbonImmutable::now(),
                        ]);
                    } catch (UniqueConstraintViolationException) {
                        // Listed by a racing pass: the same product, the same row.
                        continue;
                    }

                    $listed++;
                }
            }, 'products.id', 'id');

        return $listed;
    }

    /**
     * Every shop that lists the whole catalogue: the scheduled pass, which also
     * catches products added or made eligible since.
     */
    public function sweep(): int
    {
        $listed = 0;

        Website::query()
            ->open()
            ->where('lists_whole_catalogue', true)
            ->whereHas('businessAccount', fn ($accounts) => $accounts->where('account_type', AccountType::NonConditional->value))
            ->with('businessAccount')
            ->each(function (Website $website) use (&$listed): void {
                $listed += $this->handle($website);
            });

        return $listed;
    }

    /**
     * Put back what a switch to "selected only" took off: the rows this default
     * listed, and nothing the owner chose or hid.
     */
    protected function relist(Website $website): int
    {
        return WebsiteProduct::query()
            ->where('website_id', $website->id)
            ->where('auto_listed', true)
            ->where('status', WebsiteProductStatus::Unpublished->value)
            ->update([
                'status' => WebsiteProductStatus::Published->value,
                'published_at' => CarbonImmutable::now(),
                'unpublished_at' => null,
                'sync_status' => WebsiteSyncStatus::Pending->value,
            ]);
    }
}
