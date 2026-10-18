<?php

namespace App\Domain\Catalog;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * The products a business account has bought, which are the only ones it may put
 * on a website.
 *
 * "Bought" is a paid wholesale order of the account that was not cancelled. An
 * order that is merely placed does not count, and neither does one the account
 * never paid for. And the account must still **own units of it** (D34): the
 * purchase gave it stock, and a purchase that has since been reversed — its units
 * taken back — stops being one. A product whose units are all sold or reserved
 * still counts, because the account has accounted for every one of them.
 *
 * Buying a product makes it eligible, nothing more: it reaches a storefront only
 * when the owner selects it for that storefront. The server's answer, and the
 * only one: the catalogue, the website picker and the selection action all ask
 * here.
 */
class PurchasedProducts
{
    public function has(BusinessAccount $account, Product $product): bool
    {
        return $this->productIds($account)->where('product_id', $product->id)->exists();
    }

    /**
     * Whether the account may put this product on a website: a Non-Conditional
     * account sells straight from the catalogue and Feriwala recovers the
     * product cost from the order's collected proceeds; a Conditional account
     * must have bought it first.
     */
    public function maySell(BusinessAccount $account, Product $product): bool
    {
        return $account->isNonConditional() || $this->has($account, $product);
    }

    /**
     * Restrict a product query to what the account may sell on a website: all
     * of it for a Non-Conditional account, only what it bought otherwise.
     *
     * @param  Builder<Product>  $products
     * @return Builder<Product>
     */
    public function onlySellable(Builder $products, BusinessAccount $account): Builder
    {
        return $account->isNonConditional() ? $products : $this->only($products, $account);
    }

    /**
     * Which of these products the account has bought.
     *
     * @param  iterable<int, int>  $productIds
     * @return array<int, int> the ids it has bought, keyed by id
     */
    public function among(BusinessAccount $account, iterable $productIds): array
    {
        $ids = collect($productIds)->all();

        if ($ids === []) {
            return [];
        }

        return $this->productIds($account)
            ->whereIn('product_id', $ids)
            ->distinct()
            ->pluck('product_id')
            ->mapWithKeys(fn (mixed $id) => [(int) $id => (int) $id])
            ->all();
    }

    /**
     * Restrict a product query to what the account has bought.
     *
     * @param  Builder<Product>  $products
     * @return Builder<Product>
     */
    public function only(Builder $products, BusinessAccount $account): Builder
    {
        return $products->whereIn('products.id', $this->productIds($account));
    }

    /**
     * @return Builder<OrderItem>
     */
    protected function productIds(BusinessAccount $account): Builder
    {
        return OrderItem::query()
            ->select('product_id')
            ->whereNotNull('product_id')
            ->whereIn('order_id', Order::query()
                ->select('id')
                ->where('business_account_id', $account->id)
                ->where('source', OrderSource::ErpWholesale)
                ->whereNotNull('paid_at')
                ->whereNull('cancelled_at'))
            ->whereExists(fn ($owned) => $owned
                ->selectRaw('1')
                ->from('partner_stock_items')
                ->whereColumn('partner_stock_items.product_id', 'order_items.product_id')
                ->where('partner_stock_items.business_account_id', $account->id)
                ->whereRaw('(available + reserved + processing + sold + returned + damaged) > 0'));
    }
}
