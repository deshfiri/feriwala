<?php

namespace App\Domain\Order\Data;

/**
 * What staff asked to correct about who an order is for and where it goes
 * (D38): the customer's contact details, the delivery and billing addresses
 * and the customer's note. Nothing here can change what the order costs — the
 * structured delivery area an address is priced from is not part of it.
 *
 * Each array holds only the fields the staff sent; a field the order never
 * had is ignored rather than added.
 */
final readonly class OrderDetailsRevision
{
    /**
     * @param  array<string, string|null>  $customer
     * @param  array<string, string|null>  $shippingAddress
     * @param  array<string, string|null>  $billingAddress
     * @param  bool  $noteGiven  whether the customer note was part of the request at all
     */
    public function __construct(
        public array $customer,
        public array $shippingAddress,
        public array $billingAddress,
        public ?string $customerNote,
        public bool $noteGiven,
        public string $reason,
        public ?string $internalNote,
        public int $baseRevision,
        public string $idempotencyKey,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'customer' => $this->customer,
            'shipping_address' => $this->shippingAddress,
            'billing_address' => $this->billingAddress,
            'customer_note' => $this->noteGiven ? $this->customerNote : null,
            'note_given' => $this->noteGiven,
            'base_revision' => $this->baseRevision,
        ];
    }
}
