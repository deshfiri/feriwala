<?php

namespace App\Domain\Order\Queries;

use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\FeeRuleResolver;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductEligibility;
use App\Domain\Order\Data\WebsiteOrderLine;
use App\Domain\Order\Data\WebsiteOrderQuote;
use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Package\Entitlements;
use App\Domain\Tax\Data\TaxBreakdown;
use App\Domain\Tax\Enums\TaxScope;
use App\Domain\Tax\TaxEngine;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * What a website order costs, from the ERP's own data (contract §6.1, §6.1.1,
 * §17.3, P5-23, D12).
 *
 * **Nothing the storefront sends is a price.** For each line:
 *
 *   - the SKU must be sellable on this website — a published selection of an
 *     active product, the variation active, or the product itself when it has
 *     no variations — exactly the SKUs the inventory endpoint answers for;
 *   - the product must still be eligible for the website's owner on the
 *     dropshipping channel, and the quantity inside the product's bounds;
 *   - the price is the website's own selling price — the promotion where there
 *     is one — and is taxed per line at the rule in force for the product.
 *
 * Delivery is the website delivery fee for the owner's package, then the global
 * rule, taxed as a fee. There is no website coupon yet, so the discount is zero.
 * The customer is a consumer, never a business account, so no account's tax
 * exemption applies.
 */
class PriceWebsiteOrder
{
    public function __construct(
        protected ProductEligibility $eligibility,
        protected FeeRuleResolver $fees,
        protected TaxEngine $tax,
        protected Entitlements $entitlements,
    ) {}

    /**
     * @param  array<int, array{sku: string, quantity: int}>  $items
     *
     * @throws WebsiteOrderRefused
     */
    public function quote(Website $website, array $items, ?CarbonImmutable $at = null): WebsiteOrderQuote
    {
        $at ??= CarbonImmutable::now();
        $account = $website->businessAccount;
        $currency = Currency::tryFrom($website->currency_code) ?? Currency::BDT;

        $lines = [];
        $charges = [];
        $subtotal = Money::zero($currency);

        foreach (array_values($items) as $item) {
            $line = $this->line($website, $item['sku'], $item['quantity'], $currency, $at);

            $lines[] = $line;
            $charges[] = $line->tax;
            $subtotal = $subtotal->plus($line->subtotal);
        }

        $delivery = $lines === []
            ? Money::zero($currency)
            : $this->fees->websiteCharge(FeeType::WebsiteDelivery, $this->entitlements->activePackage($account)?->package, $currency, $at);

        $deliveryTax = $this->tax->charge(
            amount: $delivery,
            at: $at,
            scope: TaxScope::Fee,
            scopeValue: FeeType::WebsiteDelivery->value,
        );

        $charges[] = $deliveryTax;

        $tax = TaxBreakdown::of($charges, $currency);
        $discount = Money::zero($currency);

        return new WebsiteOrderQuote(
            lines: $lines,
            currency: $currency,
            subtotal: $subtotal,
            discount: $discount,
            delivery: $delivery,
            deliveryTax: $deliveryTax,
            tax: $tax,
            total: $subtotal->minus($discount)->plus($delivery)->plus($tax->addedTotal()),
        );
    }

    /**
     * @throws WebsiteOrderRefused
     */
    protected function line(Website $website, string $sku, int $quantity, Currency $currency, CarbonImmutable $at): WebsiteOrderLine
    {
        [$product, $variant] = $this->resolve($sku);

        if ($product === null) {
            throw WebsiteOrderRefused::productUnavailable($sku);
        }

        /** @var WebsiteProduct|null $selection */
        $selection = WebsiteProduct::query()
            ->where('website_id', $website->id)
            ->where('product_id', $product->id)
            ->published()
            ->first();

        $price = $selection?->sellingPrice();

        if ($selection === null || $price === null || $price->currency !== $currency || ! $price->isPositive()
            || ! $this->eligibility->isEligible($product, $website->businessAccount, SalesChannel::Dropshipping)) {
            throw WebsiteOrderRefused::productUnavailable($sku);
        }

        if (! $product->acceptsQuantity($quantity)) {
            throw WebsiteOrderRefused::quantityNotAllowed($sku, $quantity, $product->min_order_quantity, $product->max_order_quantity);
        }

        $subtotal = $price->multipliedBy($quantity);

        $charge = $this->tax->chargeGoods(
            amount: $subtotal,
            productSku: $product->sku,
            categorySlugs: $this->categorySlugs($product),
            at: $at,
        );

        return new WebsiteOrderLine(
            selection: $selection,
            product: $product,
            variant: $variant,
            sku: $variant !== null ? $variant->sku : $product->sku,
            quantity: $quantity,
            unitPrice: $price,
            subtotal: $subtotal,
            discount: Money::zero($currency),
            tax: $charge,
        );
    }

    /**
     * The product and variation a SKU names, when it names something sellable:
     * an active variation, or a product with no variations at all.
     *
     * @return array{0: Product|null, 1: ProductVariant|null}
     */
    protected function resolve(string $sku): array
    {
        $normalised = mb_strtoupper(trim($sku));

        /** @var ProductVariant|null $variant */
        $variant = ProductVariant::query()
            ->whereRaw('upper(sku) = ?', [$normalised])
            ->where('is_active', true)
            ->with('product')
            ->first();

        if ($variant !== null) {
            return [$variant->product, $variant];
        }

        /** @var Product|null $product */
        $product = Product::query()
            ->whereRaw('upper(sku) = ?', [$normalised])
            ->whereDoesntHave('variants')
            ->first();

        return [$product, null];
    }

    /**
     * The product's category, then the one above it, for tax rules that name a
     * category by its slug.
     *
     * @return array<int, string>
     */
    protected function categorySlugs(Product $product): array
    {
        $category = $product->category;

        return array_values(array_filter([$category->slug, $category->parent?->slug]));
    }
}
