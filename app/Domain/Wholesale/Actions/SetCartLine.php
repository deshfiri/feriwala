<?php

namespace App\Domain\Wholesale\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Catalog\WholesalePriceResolver;
use App\Domain\Inventory\Queries\StockAvailability;
use App\Domain\Wholesale\Exceptions\CartRefused;
use App\Domain\Wholesale\Models\Cart;
use App\Domain\Wholesale\Models\CartItem;
use App\Domain\Wholesale\Queries\PriceCart;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Put a stockable unit in the cart at a quantity, or change the quantity of one
 * already there (§14, P4-4).
 *
 * **The quantity is set, not added.** Sending the same request twice leaves the
 * same cart, so a double click or a retried request can never double an order.
 *
 * Refused before anything is written, in this order: the product must be one
 * the account may buy wholesale; a product with variations is bought per active
 * variation of its own; the quantity must sit within the product's minimum and
 * maximum order; and this account must be able to order that many now — central
 * stock in active warehouses plus what is allocated to the account itself. The
 * cart does not set stock aside: that happens when an order is placed, and the
 * cart is checked again on every read in case stock has gone since.
 *
 * The unit price the person is being shown at this quantity is remembered on the
 * line, only so a later change can be pointed out to them.
 */
class SetCartLine
{
    public function __construct(
        protected OpenCart $carts,
        protected ProductEligibility $eligibility,
        protected WholesalePriceResolver $prices,
        protected StockAvailability $stock,
    ) {}

    /**
     * @throws CartRefused
     */
    public function handle(User $user, BusinessAccount $account, Product $product, ?ProductVariant $variant, int $quantity): CartItem
    {
        if (! $this->eligibility->isEligible($product, $account, SalesChannel::Wholesale)) {
            throw CartRefused::unavailable();
        }

        $hasVariants = $product->variants()->exists();

        if ($hasVariants && $variant === null) {
            throw CartRefused::chooseVariation();
        }

        if ($variant !== null && (! $hasVariants || $variant->product_id !== $product->id || ! $variant->is_active)) {
            throw CartRefused::variationUnavailable();
        }

        if ($quantity < max(1, $product->min_order_quantity)) {
            throw CartRefused::belowMinimum(max(1, $product->min_order_quantity));
        }

        if ($product->max_order_quantity !== null && $quantity > $product->max_order_quantity) {
            throw CartRefused::aboveMaximum($product->max_order_quantity);
        }

        $sku = $variant !== null ? $variant->sku : $product->sku;
        $available = $this->stock->forUnits(
            [['sku' => $sku, 'product_id' => $product->id, 'variant_id' => $variant?->id]],
            $account,
        )[$sku]['quantity'] ?? 0;

        if ($available < $quantity) {
            throw CartRefused::insufficientStock($available);
        }

        $unit = $this->prices->unitPrice($product, $variant, $quantity);
        $cart = $this->carts->forUser($user, $account);

        return DB::transaction(function () use ($cart, $product, $variant, $quantity, $unit) {
            // One writer per cart at a time, so the line cap and the upsert see
            // the same cart.
            Cart::query()->lockForUpdate()->findOrFail($cart->id);

            /** @var CartItem|null $line */
            $line = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->where('product_variant_id', $variant?->id)
                ->first();

            if ($line !== null) {
                $line->forceFill(['quantity' => $quantity, 'unit_price_seen' => $unit])->save();

                return $line;
            }

            if (CartItem::query()->where('cart_id', $cart->id)->count() >= PriceCart::MAXIMUM_LINES) {
                throw CartRefused::cartFull(PriceCart::MAXIMUM_LINES);
            }

            return CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => $quantity,
                'currency_code' => $unit->currency->value,
                'unit_price_seen' => $unit,
            ]);
        });
    }
}
