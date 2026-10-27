<?php

namespace App\Domain\Order\Actions;

use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Order\Data\ExchangeLine;
use App\Domain\Order\Data\ExchangeQuote;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Queries\PriceExchange;
use App\Domain\Package\Entitlements;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Queries\ResolveWebsitePriceRule;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Make the replacement order an exchange sends (D35).
 *
 * **A new order, not the old one changed.** The original order is never touched:
 * the replacement has its own lines — product, variation, quantity, price — its
 * own copy of the customer and address, its own number (`EX-…`), and its own
 * tracking through the ordinary fulfilment and courier pipeline. Each line points
 * back at the line it replaces, and the order points at the case that asked for
 * it and at the original.
 *
 * It is **not a sale**: `source = exchange`, no payment of its own, and its lines
 * defer and recover nothing, so no second profit, commission or supplier payable
 * follows from goods that were already sold. For the same product the lines are
 * priced as the customer already paid and wholly covered by the goods coming
 * back, so the order is worth nothing to them; for a different product the
 * difference is on the case, exact, to be collected or refunded through the
 * existing systems.
 *
 * It starts at **pending confirmation** and holds no stock. Nothing about stock
 * moves until the returned goods have been inspected and approved: only then is
 * the replacement reserved and the order confirmed
 * ({@see ConfirmExchangeOrder}), and if the goods are refused it is cancelled
 * ({@see CancelExchangeOrder}).
 *
 * Runs inside the transaction that opens the case, so one exchange request makes
 * exactly one return case and exactly one exchange order, or neither.
 */
class CreateExchangeOrder
{
    public function __construct(
        protected PriceExchange $pricing,
        protected ResolveWebsitePriceRule $priceRules,
        protected Entitlements $entitlements,
    ) {}

    /**
     * @param  list<array{0: OrderItem, 1: int}>  $lines  the returned lines of `$source`, and how many of each
     *
     * @throws ReturnRefused
     */
    public function handle(
        OrderReturn $case,
        Order $source,
        Order $root,
        array $lines,
        ReturnSubmission $submission,
        OrderStatusChangeSource $by,
        ?User $actor = null,
    ): Order {
        /** @var Website $website */
        $website = Website::query()->findOrFail($source->website_id);

        $pairs = [];

        foreach ($lines as [$item, $quantity]) {
            $pairs[] = [
                'item' => $item,
                'quantity' => $quantity,
                // Absent means the same unit comes back as the replacement.
                'sku' => $submission->replacements[$item->sku] ?? $item->sku,
            ];
        }

        $quote = $this->pricing->quote($source, $website, $pairs);

        $order = Order::create([
            // The display number is the order's own reference: EX-{original}, -2, -3 …
            'reference' => $case->exchange_number,
            'source' => OrderSource::Exchange,
            'status' => OrderStatus::PendingConfirmation,
            'business_account_id' => $source->business_account_id,
            'account_type' => $source->account_type,
            'website_id' => $source->website_id,
            'original_order_id' => $root->id,
            'exchange_return_id' => $case->id,
            'idempotency_key' => 'exchange:'.$case->public_id,
            'customer' => $source->customer,
            'billing_address' => $source->billing_address,
            'shipping_address' => $source->shipping_address,
            'currency_code' => $source->currency_code,
            'subtotal' => $quote->subtotal,
            'discount' => $quote->discount,
            // The customer is not charged delivery for it: the one new delivery of an
            // exchange is a charge on the case, borne by whoever is responsible.
            'delivery' => Money::zero($quote->currency),
            'tax' => $quote->tax,
            'tax_included' => $quote->taxIncluded,
            'cod_fee' => Money::zero($quote->currency),
            'total' => $quote->total,
            'customer_note' => 'Replacement for '.$source->reference.'.',
            'placed_at' => CarbonImmutable::now(),
        ]);

        foreach ($quote->lines as $position => $line) {
            $this->writeLine($order, $line, $position + 1);
        }

        $order->recordPlacement(
            new StatusChange(
                actorId: $actor?->id,
                reason: 'Replacement order for exchange '.$case->exchange_number.' of '.$root->reference.'.',
                publicNote: 'orders.notes.exchange_created',
            ),
            $by === OrderStatusChangeSource::Staff ? OrderStatusChangeSource::Staff : OrderStatusChangeSource::System,
        );

        $this->recordDifference($case, $quote, $order);

        return $order->refresh();
    }

    protected function writeLine(Order $order, ExchangeLine $line, int $number): void
    {
        $order->items()->create([
            'line_number' => $number,
            'product_id' => $line->product->id,
            'product_variant_id' => $line->variant?->id,
            'website_product_id' => $line->selection?->id,
            'sku' => $line->sku,
            'product_name' => Str::limit($line->product->name, 157),
            'variant_label' => $line->variant?->values
                ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                ->pluck('value')
                ->implode(' / '),
            'quantity' => $line->quantity,
            'currency_code' => $line->subtotal->currency->value,
            'unit_price' => $line->unitPrice,
            'line_subtotal' => $line->subtotal,
            'discount' => $line->discount,
            'tax' => $line->tax,
            'tax_included' => $line->taxIncluded,
            'line_total' => $line->total(),
            'tax_code' => $line->taxCode,
            'tax_rate_basis_points' => $line->taxRateBasisPoints,
            'tax_mode' => $line->taxMode,
            'replaces_order_item_id' => $line->replaces->id,
            'suggested_unit_price' => $this->suggestedUnitPriceFor($order, $line),
        ]);
    }

    /**
     * What a damaged unit of this line is worth at most (D35): the same product
     * keeps the figure frozen when it was first sold, and a different product is
     * frozen at the platform's suggested price for it now.
     */
    protected function suggestedUnitPriceFor(Order $order, ExchangeLine $line): ?Money
    {
        if ($line->product->id === $line->replaces->product_id) {
            return $line->replaces->suggested_unit_price;
        }

        $package = $this->entitlements->activePackage($order->businessAccount)?->package;
        $suggested = $this->priceRules->for($line->product, $package)->suggested;

        return $suggested?->isPositive() === true ? $suggested : null;
    }

    /**
     * Write the exchange's money onto the case, once: the one new delivery, and
     * the price difference with who owes whom.
     */
    protected function recordDifference(OrderReturn $case, ExchangeQuote $quote, Order $order): void
    {
        $state = match (true) {
            $quote->additionalDue->isPositive() => 'collection_pending',
            $quote->refundDue->isPositive() => 'refund_due',
            default => 'not_applicable',
        };

        $case->forceFill([
            'exchange_order_id' => $order->id,
            'exchange_delivery_charge' => $quote->delivery,
            'exchange_additional_due' => $quote->additionalDue,
            'exchange_refund_due' => $quote->refundDue,
            'exchange_difference_state' => $state,
        ])->save();
    }
}
