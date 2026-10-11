<?php

namespace App\Integrations\Store\Data;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * An order as a store reported it, normalised by its driver (D29).
 *
 * Amounts are exact flat Taka, parsed from the store's own text — never through
 * a float. Anything the store did not send is null rather than guessed.
 */
final readonly class StoreOrder
{
    /**
     * @param  array{external_id: string|null, name: string|null, phone: string|null, email: string|null}  $customer
     * @param  array<string, string|null>|null  $shippingAddress
     * @param  array<string, string|null>|null  $billingAddress
     * @param  list<StoreOrderLine>  $lines
     */
    public function __construct(
        public string $externalId,
        public string $orderNumber,
        public string $status,
        public ?string $paymentStatus,
        public ?string $paymentMethod,
        public string $currencyCode,
        public Money $subtotal,
        public Money $discount,
        public ?string $couponCode,
        public Money $shippingTotal,
        public Money $tax,
        public Money $total,
        public ?Money $paidTotal,
        public array $customer,
        public ?array $shippingAddress,
        public ?array $billingAddress,
        public ?string $note,
        public ?string $cancelReason,
        public CarbonImmutable $placedAt,
        public ?CarbonImmutable $updatedAt,
        public array $lines,
    ) {}
}
