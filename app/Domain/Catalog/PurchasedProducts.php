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
 * never paid for. The server's answer, and the only one: the catalogue, the
 * website picker and the selection action all ask here.
 */
class PurchasedProducts
{
    public function has(BusinessAccount $account, Product $product): bool
    {
        return $this->productIds($account)->where('product_id', $product->id)->exists();
    }

    /**
     * Which of these products the account has bought.
     *
     * @param  iterable<int, int>  $productIds
     * @return array<int, int>  the ids it has bought, keyed by id
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
                ->whereNull('cancelled_at'));
    }
}
