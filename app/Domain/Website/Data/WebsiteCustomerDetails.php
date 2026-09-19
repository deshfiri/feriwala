<?php

namespace App\Domain\Website\Data;

/**
 * Who a storefront says its customer is, with the mobile already normalised
 * (contract §4.3.1, §6.2).
 */
readonly class WebsiteCustomerDetails
{
    public function __construct(
        public string $name,
        public string $mobile,
        public ?string $email = null,
        public ?string $reference = null,
        public bool $isGuest = true,
    ) {}

    /**
     * As an order keeps it: the customer as they were when they ordered.
     *
     * @return array<string, string|bool|null>
     */
    public function toSnapshot(string $customerId): array
    {
        return [
            'customer_id' => $customerId,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'storefront_customer_reference' => $this->reference,
            'is_guest' => $this->isGuest,
        ];
    }
}
