<?php

namespace App\Domain\Cases\Queries;

use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Queries\PriceExchange;
use App\Domain\Order\Queries\ReturnChargeCalculator;
use App\Domain\Order\Queries\ReturnEligibility;
use App\Domain\Website\Models\Website;

/**
 * What a return or an exchange would cost, before the Partner asks for it (D35).
 *
 * **The server's figures, shown as an estimate.** The return charge is half of the
 * original delivery charge, snapshotted on the order; an exchange adds the one new
 * delivery its replacement would be sent by, and the exact price difference for a
 * different product. Each is worked out from the order's own frozen figures and
 * the delivery-charge service — never from anything the browser says — and the
 * original delivery charge is shown as history, because it is not charged again.
 *
 * It is an **estimate**: who finally bears the cost, and so what is charged, is
 * decided by staff after the goods are inspected. It is worked out for exactly
 * the lines and quantities asked, and refuses what could not be asked for.
 */
class CaseEstimate
{
    public function __construct(
        protected ReturnChargeCalculator $charges,
        protected ReturnEligibility $eligibility,
        protected PriceExchange $pricing,
    ) {}

    /**
     * @param  array<string, int>  $quantities  what is coming back, by SKU
     * @param  array<string, string>  $replacements  an exchange's replacement, by the SKU it replaces
     * @return array<string, mixed>
     *
     * @throws ReturnRefused
     */
    public function for(Order $order, ReturnCaseType $type, array $quantities, array $replacements = []): array
    {
        $order->loadMissing('items');

        $returnCharge = $this->charges->returnCharge($order);
        $estimate = [
            'type' => $type->value,
            'original_delivery' => $this->charges->originalDelivery($order)->jsonSerialize(),
            'return_charge' => $returnCharge->jsonSerialize(),
            'exchange_delivery' => null,
            'estimated_total' => $returnCharge->jsonSerialize(),
            'exchange' => null,
        ];

        if ($type !== ReturnCaseType::Exchange || $quantities === []) {
            return $estimate;
        }

        $report = $this->eligibility->for($order);
        $returnable = collect($report['lines'])->keyBy('sku');
        $pairs = [];

        foreach ($quantities as $sku => $quantity) {
            if ($quantity < 1) {
                continue;
            }

            if (($returnable[$sku]['returnable'] ?? 0) < $quantity) {
                throw ReturnRefused::lineNotReturnable($sku, $quantity, (int) ($returnable[$sku]['returnable'] ?? 0));
            }

            /** @var OrderItem $item */
            $item = $order->items->firstWhere('sku', $sku);
            $pairs[] = ['item' => $item, 'quantity' => $quantity, 'sku' => $replacements[$sku] ?? $sku];
        }

        if ($pairs === []) {
            return $estimate;
        }

        /** @var Website $website */
        $website = Website::query()->findOrFail($order->website_id);
        $quote = $this->pricing->quote($order, $website, $pairs);

        $estimate['exchange_delivery'] = $quote->delivery->jsonSerialize();
        $estimate['estimated_total'] = $returnCharge->plus($quote->delivery)->jsonSerialize();
        $estimate['exchange'] = $quote->preview();

        return $estimate;
    }
}
