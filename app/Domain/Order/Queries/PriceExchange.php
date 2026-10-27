<?php

namespace App\Domain\Order\Queries;

use App\Domain\Billing\CalculateDeliveryCharge;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Order\Data\ExchangeLine;
use App\Domain\Order\Data\ExchangeQuote;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * What an exchange comes to, from the ERP's own data (D35).
 *
 * Each line of the original order that is coming back is replaced by a unit of
 * the Partner's choosing, and the server works out — exactly, in flat Taka, never
 * from a figure the client sent — what the replacement is worth against the
 * goods returned.
 *
 *   - **The same product**, in any of its variations, is priced at what the
 *     customer already paid and is wholly covered by the returned goods. It has
 *     no price difference, and so creates no second sale.
 *   - **A different product** must be one the Partner publishes on this website.
 *     It is priced at the website's selling price now, taxed at the rule in force,
 *     and the returned goods — their prorated share of what the customer paid for
 *     the line, to the poisha — are the credit against it.
 *
 * The one new delivery the exchange sends is calculated by the delivery charge
 * service from the replacement's own logistics. The original delivery is never
 * part of it.
 */
class PriceExchange
{
    public function __construct(
        protected PriceWebsiteOrder $pricing,
        protected ReturnRefundAmount $values,
        protected CalculateDeliveryCharge $delivery,
    ) {}

    /**
     * @param  Order  $source  the order the returned lines are on
     * @param  array<int, array{item: OrderItem, quantity: int, sku: string}>  $pairs  each returned line, how many, and the SKU replacing it
     *
     * @throws ReturnRefused
     */
    public function quote(Order $source, Website $website, array $pairs): ExchangeQuote
    {
        $currency = Currency::from($source->currency_code);
        $zero = Money::zero($currency);

        $lines = [];
        $subtotal = $discount = $tax = $taxIncluded = $total = $additional = $refund = $zero;
        $logistics = [];

        foreach ($pairs as $pair) {
            $line = $this->line($website, $pair['item'], $pair['quantity'], $pair['sku'], $currency);

            $lines[] = $line;
            $subtotal = $subtotal->plus($line->subtotal);
            $discount = $discount->plus($line->discount);
            $tax = $tax->plus($line->tax);
            $taxIncluded = $taxIncluded->plus($line->taxIncluded);
            $total = $total->plus($line->total());

            $difference = $line->difference();

            if ($difference->isPositive()) {
                $additional = $additional->plus($difference);
            } elseif ($difference->isNegative()) {
                $refund = $refund->plus($difference->negated());
            }

            $logistics[] = [
                'logistics' => ($line->variant ?? $line->product)->logistics(),
                'quantity' => $line->quantity,
            ];
        }

        // What each side owes the other is one number, in one direction.
        $net = $additional->minus($refund);
        [$additional, $refund] = $net->isPositive()
            ? [$net, $zero]
            : [$zero, $net->negated()];

        $charge = $this->delivery->handle($logistics, $currency);

        return new ExchangeQuote(
            lines: $lines,
            currency: $currency,
            subtotal: $subtotal,
            discount: $discount,
            tax: $tax,
            taxIncluded: $taxIncluded,
            total: $total,
            additionalDue: $additional,
            refundDue: $refund,
            delivery: $charge->finalCharge,
            deliveryCalculation: $charge->toArray(),
        );
    }

    /**
     * @throws ReturnRefused
     */
    protected function line(Website $website, OrderItem $item, int $quantity, string $sku, Currency $currency): ExchangeLine
    {
        [$product, $variant] = $this->resolve($sku);

        if ($product === null) {
            throw ReturnRefused::replacementUnavailable($sku);
        }

        $credit = $this->values->valueOf($item, $quantity);

        if ($product->id === $item->product_id) {
            return $this->sameProduct($item, $product, $variant, $quantity, $currency, $credit);
        }

        try {
            $priced = $this->pricing->quote($website, [['sku' => $sku, 'quantity' => $quantity]])->lines[0];
        } catch (WebsiteOrderRefused) {
            throw ReturnRefused::replacementUnavailable($sku);
        }

        $subtotal = $priced->subtotal;
        $covered = $credit->lessThan($subtotal) ? $credit : $subtotal;
        $taxed = ! $priced->tax->isZero();

        return new ExchangeLine(
            replaces: $item,
            product: $priced->product,
            variant: $priced->variant,
            selection: $priced->selection,
            sku: $priced->sku,
            quantity: $quantity,
            unitPrice: $priced->unitPrice,
            subtotal: $subtotal,
            discount: $covered,
            tax: $priced->addedTax(),
            taxIncluded: $priced->includedTax(),
            taxCode: $taxed ? $priced->tax->code : null,
            taxRateBasisPoints: $taxed ? $priced->tax->rateBasisPoints : null,
            taxMode: $taxed ? $priced->tax->mode->value : null,
            sameProduct: false,
            credit: $credit,
        );
    }

    protected function sameProduct(OrderItem $item, Product $product, ?ProductVariant $variant, int $quantity, Currency $currency, Money $credit): ExchangeLine
    {
        $unit = $item->unit_price;
        $subtotal = $unit->multipliedBy($quantity);

        /** @var WebsiteProduct|null $selection */
        $selection = $item->website_product_id === null ? null : WebsiteProduct::query()->find($item->website_product_id);

        return new ExchangeLine(
            replaces: $item,
            product: $product,
            variant: $variant,
            selection: $selection,
            sku: $variant !== null ? $variant->sku : $product->sku,
            quantity: $quantity,
            unitPrice: $unit,
            subtotal: $subtotal,
            // The returned goods cover it entirely: no new sale, nothing owed.
            discount: $subtotal,
            tax: Money::zero($currency),
            taxIncluded: Money::zero($currency),
            taxCode: null,
            taxRateBasisPoints: null,
            taxMode: null,
            sameProduct: true,
            credit: $credit,
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
}
