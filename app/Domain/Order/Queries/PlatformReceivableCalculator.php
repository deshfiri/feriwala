<?php

namespace App\Domain\Order\Queries;

use App\Domain\Order\Data\ReceivableBreakdown;
use App\Domain\Order\Data\ReceivableLine;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\ReceivableComponent;
use App\Domain\Order\Exceptions\PlatformReceivableRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Tax\Data\TaxCharge;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * What Banij is owed on a Store Order paid to a Partner's own gateway (D36).
 *
 * The one place the amount is worked out:
 *
 *     receivable = Σ OrderItem::recoverableAmount() + delivery charge + delivery tax
 *
 * `recoverableAmount()` is Feriwala's own rule for what it keeps from a line —
 * the frozen platform amount (else the frozen product cost, never both) plus the
 * tax on that line — and is the authority. This only splits it into its product
 * and tax parts so each can be explained and reversed, and refuses the order if
 * the two do not agree.
 *
 * Nothing is invented. No commission, service fee, courier estimate, Supplier
 * delivery-success fee or gateway fee exists on a Store Order, so none is added.
 * A line with no frozen figure is **refused**, never counted as zero, and an
 * order whose frozen tax or delivery does not reconcile with the quote it was
 * built from is refused rather than adjusted.
 *
 * Delivery tax is added only when it was added to the customer's price. Tax that
 * was already inside the delivery charge is in the charge, so it is recorded
 * (inclusive, zero added) and never counted twice.
 *
 * Pure: it reads the order's frozen snapshot and the quote's figures, and writes
 * nothing.
 */
class PlatformReceivableCalculator
{
    /**
     * @throws PlatformReceivableRefused
     */
    public function calculate(Order $order, Money $delivery, TaxCharge $deliveryTax): ReceivableBreakdown
    {
        if ($order->source !== OrderSource::Website || $order->isExchange()) {
            throw PlatformReceivableRefused::notAStoreOrder();
        }

        if (! $order->isNonConditional()) {
            throw PlatformReceivableRefused::notNonConditional();
        }

        $currency = $delivery->currency;

        if ($order->currency_code !== $currency->value) {
            throw PlatformReceivableRefused::currencyMismatch();
        }

        /** @var Collection<int, OrderItem> $items */
        $items = $order->items()->orderBy('line_number')->get();

        if ($items->isEmpty()) {
            throw PlatformReceivableRefused::noLines();
        }

        $lines = [];
        $addedTax = Money::zero($currency);
        $includedTax = Money::zero($currency);

        foreach ($items as $item) {
            $kept = $item->platform_retained_amount ?? $item->product_cost;

            if ($kept === null) {
                throw PlatformReceivableRefused::undefinedLine($item->line_number);
            }

            if ($kept->currency !== $currency || $item->tax->currency !== $currency || $item->tax_included->currency !== $currency) {
                throw PlatformReceivableRefused::currencyMismatch();
            }

            $basis = $item->platform_retained_amount !== null ? 'platform_retained_amount' : 'product_cost';
            $tax = $item->tax->plus($item->tax_included);

            // The product part and the tax part must add back to the canonical rule.
            if (! $kept->plus($tax)->equals($item->recoverableAmount())) {
                throw PlatformReceivableRefused::disagreesWithOrder();
            }

            $lines[] = new ReceivableLine(
                component: ReceivableComponent::ProductRecovery,
                key: "line-{$item->line_number}:product_recovery",
                orderItemId: $item->id,
                basis: $basis,
                taxMode: null,
                description: "Platform recovery for {$item->sku} × {$item->quantity}",
                quantity: $item->quantity,
                unitAmount: $this->unitAmount($item, $basis, $kept),
                amount: $kept,
                ruleReference: "order_item.recoverable_amount:{$basis}",
            );

            if (! $tax->isZero()) {
                $lines[] = new ReceivableLine(
                    component: ReceivableComponent::ProductTax,
                    key: "line-{$item->line_number}:product_tax",
                    orderItemId: $item->id,
                    basis: null,
                    taxMode: $item->tax_mode,
                    description: "Tax on {$item->sku} × {$item->quantity}",
                    quantity: $item->quantity,
                    unitAmount: null,
                    amount: $tax,
                    ruleReference: 'order_item.recoverable_amount:tax',
                );
            }

            $addedTax = $addedTax->plus($item->tax);
            $includedTax = $includedTax->plus($item->tax_included);
        }

        $deliveryAddedTax = $deliveryTax->mode->isInclusive() ? Money::zero($currency) : $deliveryTax->tax;
        $deliveryIncludedTax = $deliveryTax->mode->isInclusive() ? $deliveryTax->tax : Money::zero($currency);

        // The figures this is built from must be the figures the order froze.
        if (! $delivery->equals($order->delivery)) {
            throw PlatformReceivableRefused::doesNotReconcile('delivery charge');
        }

        if (! $addedTax->plus($deliveryAddedTax)->equals($order->tax)) {
            throw PlatformReceivableRefused::doesNotReconcile('tax');
        }

        if (! $includedTax->plus($deliveryIncludedTax)->equals($order->tax_included)) {
            throw PlatformReceivableRefused::doesNotReconcile('included tax');
        }

        $lines[] = new ReceivableLine(
            component: ReceivableComponent::DeliveryCharge,
            key: 'order:delivery_charge',
            orderItemId: null,
            basis: null,
            taxMode: null,
            description: 'Delivery charge',
            quantity: 1,
            unitAmount: $delivery,
            amount: $delivery,
            ruleReference: 'order.delivery',
        );

        if (! $deliveryTax->isZero()) {
            $lines[] = new ReceivableLine(
                component: ReceivableComponent::DeliveryTax,
                key: 'order:delivery_tax',
                orderItemId: null,
                basis: null,
                taxMode: $deliveryTax->mode->value,
                description: $deliveryTax->mode->isInclusive()
                    ? 'Tax included in the delivery charge (not added again)'
                    : 'Tax charged on the delivery charge',
                quantity: 1,
                unitAmount: null,
                amount: $deliveryAddedTax,
                ruleReference: 'quote.delivery_tax',
            );
        }

        return new ReceivableBreakdown($currency, $lines);
    }

    /**
     * One unit's share, only where it is exact: the suggested unit price the line
     * froze, when it times the quantity is the frozen amount. Never a rounded quotient.
     */
    protected function unitAmount(OrderItem $item, string $basis, Money $kept): ?Money
    {
        if ($item->quantity === 1) {
            return $kept;
        }

        if ($basis === 'platform_retained_amount'
            && $item->suggested_unit_price !== null
            && $item->suggested_unit_price->multipliedBy($item->quantity)->equals($kept)) {
            return $item->suggested_unit_price;
        }

        return null;
    }
}
