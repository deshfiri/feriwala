<?php

namespace App\Domain\Website\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Package\Entitlements;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Queries\ResolveWebsitePriceRule;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A partner chooses a central product for one of their storefronts (§15, P5-2).
 *
 * **Selection, not authorship.** The product already exists in Feriwala's
 * catalogue; this records that one shop sells it. Whether the account may see
 * the product at all is {@see ProductEligibility}'s answer on the dropshipping
 * channel, asked again here rather than trusted from the screen that offered
 * it — the browser has had a form open for ten minutes and a product can be
 * withdrawn in nine.
 *
 * A selection opens **unpublished and unpriced**, with the suggested price
 * filled in where the administrator set one. Publishing is a separate act with
 * its own checks, because putting something on sale at a price nobody chose is
 * how a shop sells at the wrong figure.
 */
class SelectProductForWebsite
{
    public function __construct(
        protected ProductEligibility $eligibility,
        protected Entitlements $entitlements,
        protected ResolveWebsitePriceRule $pricing,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws WebsiteRefused
     */
    public function handle(Website $website, Product $product, User $actor): WebsiteProduct
    {
        if ($website->status->isTerminal()) {
            throw WebsiteRefused::notEditable();
        }

        $account = $website->businessAccount;

        if (! $this->eligibility->isEligible($product, $account, SalesChannel::Dropshipping)) {
            throw WebsiteRefused::productNotEligible();
        }

        $terms = $this->pricing->for($product, $this->entitlements->activePackage($account)?->package);

        if (WebsiteProduct::query()->where('website_id', $website->id)->where('product_id', $product->id)->exists()) {
            throw WebsiteRefused::alreadySelected();
        }

        try {
            // Inside its own savepoint, so a duplicate that slipped past the
            // check above — two tabs at once — rolls back only itself.
            $selection = $this->database->transaction(fn () => WebsiteProduct::create([
                'website_id' => $website->id,
                'business_account_id' => $website->business_account_id,
                'product_id' => $product->id,
                'status' => WebsiteProductStatus::Selected,
                'sync_status' => WebsiteSyncStatus::Pending,
                'currency_code' => $website->currency_code,

                // What Feriwala suggests, where it suggests anything. The
                // partner may change it within the bounds; nothing is on sale
                // until they publish it.
                'price' => $terms->suggested,
                'created_by' => $actor->id,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Choosing it twice is the same choice.
            throw WebsiteRefused::alreadySelected();
        }

        $this->audit->handle(new AuditEntry(
            action: 'website.product_selected',
            actorId: $actor->id,
            auditableType: WebsiteProduct::class,
            auditableId: $selection->id,
            after: ['website_id' => $website->id, 'product_id' => $product->id],
            accountId: $website->business_account_id,
            module: 'website',
        ));

        return $selection;
    }
}
