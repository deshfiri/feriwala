<?php

namespace App\Domain\Order\Actions;

use App\Domain\Account\Enums\AccountType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\GatewayOwnership;
use App\Domain\Order\Data\ReceivableLine;
use App\Domain\Order\Data\WebsiteOrderQuote;
use App\Domain\Order\Exceptions\PlatformReceivableRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPlatformReceivable;
use App\Domain\Order\Queries\PlatformReceivableCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

/**
 * Freezes what Banij is owed on a Store Order, once (D36).
 *
 * Written from the order's own frozen lines and the quote it was placed from, in
 * the same moment the order is, so a later change to a price, a fee or a tax rate
 * cannot reach it. The delivery tax comes from the quote's own breakdown: it is
 * the one figure the order does not store on its own, and it is never worked out
 * by subtracting totals.
 *
 * **Once per order.** The unique index on `order_id` is what makes a repeat
 * answer with the record that is already there instead of writing a second.
 * Nothing already frozen is ever updated, and an order placed before this
 * existed is never given one: there is no backfill, because the delivery tax of
 * an old order is not recoverable without guessing.
 */
class FreezePlatformReceivable
{
    public function __construct(
        protected PlatformReceivableCalculator $calculator,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws PlatformReceivableRefused
     */
    public function handle(Order $order, WebsiteOrderQuote $quote, ?AccountType $accountType = null): OrderPlatformReceivable
    {
        $existing = OrderPlatformReceivable::query()->where('order_id', $order->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $breakdown = $this->calculator->calculate($order, $quote->delivery, $quote->deliveryTax);

        if ($order->website_id === null) {
            throw new LogicException('A Store Order belongs to a Storefront.');
        }

        // The type the placement decision was made under. Read here only when the
        // caller did not hand it over, and never again afterwards.
        $accountType ??= BusinessAccount::query()->findOrFail($order->business_account_id)->account_type;

        try {
            return $this->database->transaction(function () use ($order, $breakdown, $accountType): OrderPlatformReceivable {
                $now = CarbonImmutable::now();

                $receivable = OrderPlatformReceivable::query()->create([
                    'order_id' => $order->id,
                    'payment_id' => $order->payment_id,
                    'business_account_id' => $order->business_account_id,
                    'website_id' => $order->website_id,
                    'currency_code' => $breakdown->currency->value,
                    'total' => $breakdown->total(),
                    'calculation_version' => OrderPlatformReceivable::CALCULATION_VERSION,
                    'account_type_at_placement' => $accountType->value,
                    'eligibility_version' => OrderPlatformReceivable::ELIGIBILITY_VERSION,
                    'gateway_ownership' => GatewayOwnership::Partner->value,
                    'frozen_at' => $now,
                ]);

                foreach ($breakdown->lines as $line) {
                    /** @var ReceivableLine $line */
                    $receivable->lines()->create([
                        'order_item_id' => $line->orderItemId,
                        'component' => $line->component,
                        'component_key' => $line->key,
                        'basis' => $line->basis,
                        'tax_mode' => $line->taxMode,
                        'description' => $line->description,
                        'quantity' => $line->quantity,
                        'unit_amount' => $line->unitAmount,
                        'line_amount' => $line->amount,
                        'assessed_amount' => $line->assessedAmount,
                        'currency_code' => $breakdown->currency->value,
                        'rule_reference' => $line->ruleReference,
                    ]);
                }

                return $receivable->load('lines');
            });
        } catch (UniqueConstraintViolationException) {
            // Two placements raced to freeze the same order; the loser reads the winner's.
            return OrderPlatformReceivable::query()->where('order_id', $order->id)->firstOrFail();
        }
    }
}
