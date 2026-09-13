<?php

namespace App\Domain\Catalog;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\AccountScope;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Package\Entitlements;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether a business account may see a product at all (§11.1, §12).
 *
 * The server's answer, and the only one: every screen and endpoint that shows a
 * partner a product asks here or through {@see query()}, so eligibility is never
 * reimplemented in a controller and never decided by what a page chose to hide.
 *
 * A product is eligible for an account when every one of these holds:
 *
 *   - it is **Active** — nothing else is offered to anybody (§11.2);
 *   - its **category is available and its brand, if any, is switched on** —
 *     switching a range off (§11.3) takes its products off every partner's
 *     catalogue without rewriting a single product, so switching it back on
 *     restores exactly what was there;
 *   - the account **can transact** — a suspended or unactivated account sees
 *     nothing, whatever its package says;
 *   - the account has an **entitling package** — read through
 *     {@see Entitlements}, so a lapsed subscription stops granting on the day it
 *     lapses;
 *   - that package is one the product is offered to, or the product is offered
 *     to every package;
 *   - the product is not restricted to named accounts, or this account is named.
 *
 * {@see refusals()} and {@see query()} state the same rules twice, once for one
 * product and once as SQL for a list. The tests hold them to the same answers.
 */
class ProductEligibility
{
    public const NOT_ACTIVE = 'not_active';

    public const ACCOUNT_CANNOT_TRANSACT = 'account_cannot_transact';

    public const NO_ACTIVE_PACKAGE = 'no_active_package';

    public const PACKAGE_NOT_ELIGIBLE = 'package_not_eligible';

    public const ACCOUNT_NOT_LISTED = 'account_not_listed';

    public const CHANNEL_DISABLED = 'channel_disabled';

    public const PACKAGE_WITHOUT_CHANNEL = 'package_without_channel';

    public const CATEGORY_SWITCHED_OFF = 'category_switched_off';

    public const BRAND_SWITCHED_OFF = 'brand_switched_off';

    public function __construct(
        protected Entitlements $entitlements,
    ) {}

    public function isEligible(Product $product, BusinessAccount $account, ?SalesChannel $channel = null): bool
    {
        return $this->refusals($product, $account, $channel) === [];
    }

    /**
     * Every reason this account may not see this product. Empty means it may.
     *
     * With a channel, two more conditions: the product is switched on for that
     * channel, and the account's package includes it (§8.1, §10). Without one,
     * the answer is about the catalogue in general and says nothing about how
     * the product may be used.
     *
     * @return array<int, string>
     */
    public function refusals(Product $product, BusinessAccount $account, ?SalesChannel $channel = null): array
    {
        $refusals = [];

        if (! $product->status->isSellable()) {
            $refusals[] = self::NOT_ACTIVE;
        }

        if (! $product->category->isAvailable()) {
            $refusals[] = self::CATEGORY_SWITCHED_OFF;
        }

        if ($product->brand !== null && ! $product->brand->is_active) {
            $refusals[] = self::BRAND_SWITCHED_OFF;
        }

        if (! $account->canTransact()) {
            $refusals[] = self::ACCOUNT_CANNOT_TRANSACT;
        }

        $subscription = $this->entitlements->activePackage($account);

        if ($subscription === null) {
            $refusals[] = self::NO_ACTIVE_PACKAGE;
        } elseif (
            $product->package_scope === PackageScope::SelectedPackages
            && ! $product->eligiblePackages()->whereKey($subscription->package_id)->exists()
        ) {
            $refusals[] = self::PACKAGE_NOT_ELIGIBLE;
        }

        if (
            $product->account_scope === AccountScope::SelectedAccounts
            && ! $product->eligibleAccounts()->whereKey($account->id)->exists()
        ) {
            $refusals[] = self::ACCOUNT_NOT_LISTED;
        }

        if ($channel !== null) {
            if (! $product->sellsThrough($channel)) {
                $refusals[] = self::CHANNEL_DISABLED;
            }

            if ($subscription !== null && ! $this->entitlements->allows($account, $channel->facility())) {
                $refusals[] = self::PACKAGE_WITHOUT_CHANNEL;
            }
        }

        return $refusals;
    }

    /**
     * Whether the account's package lets it use this channel at all.
     */
    public function allowsChannel(BusinessAccount $account, SalesChannel $channel): bool
    {
        return $account->canTransact() && $this->entitlements->allows($account, $channel->facility());
    }

    /**
     * The products this account may see, as a query to filter and page further.
     *
     * An account that cannot transact or holds no entitling package gets a query
     * that matches nothing, rather than an exception: an empty catalogue is the
     * honest answer to "what may I sell".
     *
     * @return Builder<Product>
     */
    public function query(BusinessAccount $account, ?SalesChannel $channel = null): Builder
    {
        $subscription = $account->canTransact() ? $this->entitlements->activePackage($account) : null;

        if ($subscription === null || ($channel !== null && ! $this->entitlements->allows($account, $channel->facility()))) {
            return Product::query()->whereRaw('1 = 0');
        }

        return Product::query()
            ->where('status', ProductStatus::Active)

            /*
             * The category and, when it has one, its parent are both switched
             * on. One level of parent is the whole tree: categories go
             * {@see Category::MAX_DEPTH} deep, and the tests hold this to
             * `isAvailable()`.
             */
            ->whereHas('category', fn (Builder $categories) => $categories
                ->where('is_active', true)
                ->where(fn (Builder $inner) => $inner
                    ->whereNull('parent_id')
                    ->orWhereHas('parent', fn (Builder $parent) => $parent->where('is_active', true))))
            ->where(fn (Builder $query) => $query
                ->whereNull('brand_id')
                ->orWhereHas('brand', fn (Builder $brands) => $brands->where('is_active', true)))
            ->when($channel !== null, fn (Builder $query) => $query->where($channel?->column() ?? 'status', $channel?->enabled()))
            ->where(fn (Builder $query) => $query
                ->where('package_scope', PackageScope::AllPackages)
                ->orWhereHas('eligiblePackages', fn (Builder $packages) => $packages->whereKey($subscription->package_id)))
            ->where(fn (Builder $query) => $query
                ->where('account_scope', AccountScope::AnyAccount)
                ->orWhereHas('eligibleAccounts', fn (Builder $accounts) => $accounts->whereKey($account->id)));
    }
}
