<?php

namespace App\Integrations\Store\Data;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * An order as a store reported it, normalised by its driver (D29).
 *
 * Amounts are exact flat Taka, parsed from the store's own text — never through
 * a float. **Anything the store did not send is null, never a guess**: a
 * webhook often carries only what changed, and a missing discount is an
 * unknown discount, not a zero one. Whoever records the order decides what an
 * absent value means — keep what is known, or wait for a complete copy.
 *
 * `$lines` is null when the store did not send the items at all, and a list
 * (possibly empty) when it did.
 */
final readonly class StoreOrder
{
    /**
     * @param  array{external_id: string|null, name: string|null, phone: string|null, email: string|null}  $customer
     * @param  array<string, string|null>|null  $shippingAddress
     * @param  array<string, string|null>|null  $billingAddress
     * @param  list<StoreOrderLine>|null  $lines
     */
    public function __construct(
        public ?string $externalId,
        public string $orderNumber,
        public ?string $status,
        public ?string $paymentStatus,
        public ?string $paymentMethod,
        public string $currencyCode,
        public ?Money $subtotal,
        public ?Money $discount,
        public ?string $couponCode,
        public ?Money $shippingTotal,
        public ?Money $tax,
        public ?Money $total,
        public ?Money $paidTotal,
        public array $customer,
        public ?array $shippingAddress,
        public ?array $billingAddress,
        public ?string $note,
        public ?string $cancelReason,
        public ?CarbonImmutable $placedAt,
        public ?CarbonImmutable $updatedAt,
        public ?array $lines,
    ) {}

    /**
     * What stops this copy from creating a new order, or null when nothing does.
     *
     * A new order needs the store's own id (the sync cursor walks by it, and it
     * is how a later copy is matched), its status, when it was placed, every
     * amount, and its lines. A webhook short of any of these is not turned into
     * a half-known order; the order arrives complete through the pull API.
     */
    public function missingForCreation(): ?string
    {
        $missing = array_keys(array_filter([
            'id' => $this->externalId === null,
            'status' => $this->status === null,
            'placed_at' => $this->placedAt === null,
            'subtotal' => $this->subtotal === null,
            'discount' => $this->discount === null,
            'shipping' => $this->shippingTotal === null,
            'tax' => $this->tax === null,
            'total' => $this->total === null,
            'items' => $this->lines === null,
        ]));

        return $missing === [] ? null : 'missing '.implode(', ', $missing);
    }
}
