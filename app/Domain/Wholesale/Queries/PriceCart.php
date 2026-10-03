<?php

namespace App\Domain\Wholesale\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Catalog\WholesalePriceResolver;
use App\Domain\Inventory\Queries\StockAvailability;
use App\Domain\Inventory\StockEnforcement;
use App\Domain\Wholesale\Data\CartLineQuote;
use App\Domain\Wholesale\Data\CartQuote;
use App\Domain\Wholesale\Models\Cart;
use App\Domain\Wholesale\Models\CartItem;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * Price a cart on the server, every time (§14, §36.1, P4-5).
 *
 * Nothing the browser sent survives into these figures. Each line is checked
 * again against what is true now, through the services that own each answer:
 *
 *   - **eligibility** — {@see ProductEligibility}, on the wholesale channel;
 *   - **variation** — a product with variations is bought per active variation;
 *   - **quantity** — the product's minimum and maximum order;
 *   - **stock** — {@see StockAvailability} for this account: active warehouses,
 *     plus stock allocated to the account itself, and nobody else's allocation;
 *   - **price** — {@see WholesalePriceResolver} at the line's quantity, so the
 *     quantity pricing in force today is the price charged.
 *
 * A problem on a line keeps it out of the subtotal; a unit price that differs
 * from what the person was last shown is flagged, never silently charged.
 */
class PriceCart
{
    /** A cart is a purchase in the making, not a wish list. */
    public const MAXIMUM_LINES = 100;

    public function __construct(
        protected ProductEligibility $eligibility,
        protected WholesalePriceResolver $prices,
        protected StockAvailability $stock,
        protected StockEnforcement $enforcement,
    ) {}

    public function quote(?Cart $cart, BusinessAccount $account): CartQuote
    {
        if ($cart === null) {
            return CartQuote::empty();
        }

        $items = $cart->items()
            ->with([
                'product.category.parent',
                'product.brand',
                'product.variants:id,product_id,sku,is_active',
                'variant.values.attribute',
            ])
            ->get();

        if ($items->isEmpty()) {
            return CartQuote::empty();
        }

        $available = $this->stock->forUnits(
            $items->map(fn (CartItem $item) => [
                'sku' => $item->variant !== null ? $item->variant->sku : $item->product->sku,
                'product_id' => $item->product_id,
                'variant_id' => $item->product_variant_id,
            ])->all(),
            $account,
        );

        $lines = [];
        $subtotal = Money::zero(Currency::BDT);

        foreach ($items as $item) {
            $line = $this->line($item, $account, $available);
            $lines[] = $line;

            if ($line->isPurchasable() && $line->lineTotal !== null) {
                $subtotal = $subtotal->plus($line->lineTotal);
            }
        }

        return new CartQuote($lines, $subtotal);
    }

    /**
     * @param  array<string, array{sku: string, in_stock: bool, quantity: int, updated_at: string|null}>  $available
     */
    protected function line(CartItem $item, BusinessAccount $account, array $available): CartLineQuote
    {
        $product = $item->product;
        $variant = $item->variant;
        $problems = [];

        $eligible = $this->eligibility->isEligible($product, $account, SalesChannel::Wholesale);

        if (! $eligible) {
            $problems[] = CartLineQuote::UNAVAILABLE;
        }

        if ($variant === null && $product->variants->isNotEmpty()) {
            $problems[] = CartLineQuote::CHOOSE_VARIATION;
        }

        if ($variant !== null && ! $variant->is_active) {
            $problems[] = CartLineQuote::VARIATION_UNAVAILABLE;
        }

        if ($item->quantity < $product->min_order_quantity) {
            $problems[] = CartLineQuote::BELOW_MINIMUM;
        }

        if ($product->max_order_quantity !== null && $item->quantity > $product->max_order_quantity) {
            $problems[] = CartLineQuote::ABOVE_MAXIMUM;
        }

        $sku = $variant !== null ? $variant->sku : $product->sku;
        $units = $available[$sku]['quantity'] ?? 0;

        if ($eligible && $units < $item->quantity && $this->enforcement->enforced()) {
            $problems[] = CartLineQuote::INSUFFICIENT_STOCK;
        }

        if (! $eligible) {
            return new CartLineQuote($item, 0, null, null, null, $item->unit_price_seen, false, $problems);
        }

        $unit = $this->prices->unitPrice($product, $variant, $item->quantity);
        $seen = $item->unit_price_seen;

        return new CartLineQuote(
            item: $item,
            available: $units,
            unitPrice: $unit,
            basePrice: $this->prices->basePrice($product, $variant),
            lineTotal: $unit->multipliedBy($item->quantity),
            unitPriceSeen: $seen,
            priceChanged: $seen !== null && ! $seen->equals($unit),
            problems: $problems,
        );
    }
}
