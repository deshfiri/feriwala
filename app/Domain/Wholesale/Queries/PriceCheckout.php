<?php

namespace App\Domain\Wholesale\Queries;

use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Models\UserAddress;
use App\Domain\Billing\CouponValidator;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\FeeRuleResolver;
use App\Domain\Catalog\Models\Product;
use App\Domain\Package\Entitlements;
use App\Domain\Tax\Data\TaxBreakdown;
use App\Domain\Tax\Enums\TaxScope;
use App\Domain\Tax\TaxEngine;
use App\Domain\Wholesale\Data\CartLineQuote;
use App\Domain\Wholesale\Data\CheckoutLineCharge;
use App\Domain\Wholesale\Data\CheckoutQuote;
use App\Domain\Wholesale\Models\Cart;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Price a wholesale checkout on the server, every time (§14, §36.1).
 *
 * Built only on the services that own each answer, so there is one of each:
 *
 *   - **goods** — {@see PriceCart}: eligibility, variation, quantity, stock and
 *     the unit price at quantity;
 *   - **discount** — {@see CouponValidator}, checked again against the subtotal
 *     just priced, so a code that has since expired, run out or stopped
 *     qualifying simply stops taking anything off, and says why;
 *   - **delivery** — {@see FeeRuleResolver}, for the account's package;
 *   - **tax** — {@see TaxEngine}, per line on what the line costs after its share
 *     of the discount, and on the delivery charge.
 *
 * Tax exclusive of the price is added to the total; tax inclusive in it is shown
 * and never added again.
 */
class PriceCheckout
{
    /**
     * The finest weighting the discount is shared out at when the exact line
     * totals are too large to multiply safely.
     */
    protected const SHARE_PARTS = 1_000_000;

    public function __construct(
        protected PriceCart $pricing,
        protected CouponValidator $coupons,
        protected FeeRuleResolver $fees,
        protected TaxEngine $tax,
        protected Entitlements $entitlements,
    ) {}

    public function quote(?Cart $cart, BusinessAccount $account, ?CarbonImmutable $at = null): CheckoutQuote
    {
        $at ??= CarbonImmutable::now();

        $goods = $this->pricing->quote($cart, $account);
        $currency = $goods->subtotal->currency;

        $coupon = $cart?->coupon_code === null
            ? null
            : $this->coupons->validateForWholesale($cart->coupon_code, $account, $goods->subtotal, $at);

        $discount = $coupon !== null && $coupon->isAccepted && $coupon->discount !== null
            ? $coupon->discount
            : Money::zero($currency);

        /** @var array<int, array{0: CartLineQuote, 1: Money}> $sold */
        $sold = [];

        foreach ($goods->lines as $line) {
            if ($line->isPurchasable() && $line->lineTotal !== null) {
                $sold[] = [$line, $line->lineTotal];
            }
        }

        $delivery = $sold === []
            ? Money::zero($currency)
            : $this->fees->wholesaleDelivery($this->entitlements->activePackage($account)?->package, $currency, $at);

        $charges = [];
        $lineCharges = [];
        $shares = $this->shareDiscount($discount, array_map(fn (array $line) => $line[1], $sold));

        foreach ($sold as $index => [$line, $lineTotal]) {
            $product = $line->item->product;
            $net = $lineTotal->minus($shares[$index]);

            $charge = $this->tax->chargeGoods(
                amount: $net->isNegative() ? Money::zero($currency) : $net,
                productSku: $product->sku,
                categorySlugs: $this->categorySlugs($product),
                account: $account,
                at: $at,
            );

            $charges[] = $charge;
            $lineCharges[] = new CheckoutLineCharge($line, $shares[$index], $charge);
        }

        $deliveryTax = $this->tax->charge(
            amount: $delivery,
            account: $account,
            at: $at,
            scope: TaxScope::Fee,
            scopeValue: FeeType::WholesaleDelivery->value,
        );

        $charges[] = $deliveryTax;

        $tax = TaxBreakdown::of($charges, $currency);

        return new CheckoutQuote(
            cart: $goods,
            coupon: $coupon,
            discount: $discount,
            delivery: $delivery,
            tax: $tax,
            total: $goods->subtotal->minus($discount)->plus($delivery)->plus($tax->addedTotal()),
            billingAddress: $cart === null ? null : UserAddress::query()->currentFor($cart->user_id, AddressType::Billing)->first(),
            shippingAddress: $cart === null ? null : UserAddress::query()->currentFor($cart->user_id, AddressType::Shipping)->first(),
            lineCharges: $lineCharges,
            deliveryTax: $deliveryTax,
        );
    }

    /**
     * The discount shared across the lines in proportion to what each costs,
     * without losing or inventing a poisha — so tax is charged on what each line
     * actually sells for.
     *
     * @param  array<int, Money>  $lineTotals
     * @return array<int, Money>
     */
    protected function shareDiscount(Money $discount, array $lineTotals): array
    {
        if ($lineTotals === [] || ! $discount->isPositive()) {
            return array_map(fn (Money $total) => Money::zero($total->currency), $lineTotals);
        }

        $weights = array_map(fn (Money $total) => $total->minorUnits, $lineTotals);
        $sum = array_sum($weights);

        // Money::allocate multiplies the amount by each weight. Past what an
        // integer holds, weigh in millionths of the subtotal instead.
        if ($discount->minorUnits > intdiv(PHP_INT_MAX, max(1, $sum))) {
            $step = intdiv($sum, self::SHARE_PARTS) + 1;
            $weights = array_map(fn (int $weight) => intdiv($weight, $step), $weights);
        }

        return array_values($discount->allocate($weights));
    }

    /**
     * The product's category, then the category above it, for tax rules that
     * name a category by its slug.
     *
     * @return array<int, string>
     */
    protected function categorySlugs(Product $product): array
    {
        $category = $product->category;

        return array_values(array_filter([$category->slug, $category->parent?->slug]));
    }
}
