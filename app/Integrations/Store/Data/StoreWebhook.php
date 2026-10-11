<?php

namespace App\Integrations\Store\Data;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * A store notification, read by its driver (D29).
 *
 * `$order` is set for an order event the driver could read; `$payment` for a
 * payment confirmation. Anything else is an event this integration does not
 * use, and is acknowledged without being applied.
 */
final readonly class StoreWebhook
{
    /**
     * @param  array{order_number: string, amount: Money|null, method: string|null, received_at: CarbonImmutable|null}|null  $payment
     */
    public function __construct(
        public string $event,
        public ?StoreOrder $order = null,
        public ?array $payment = null,
    ) {}

    public function isUsed(): bool
    {
        return $this->order !== null || $this->payment !== null;
    }
}
