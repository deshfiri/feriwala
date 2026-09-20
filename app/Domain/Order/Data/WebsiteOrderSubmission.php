<?php

namespace App\Domain\Order\Data;

use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Support\Money\Money;

/**
 * An order as a storefront submitted it, checked for shape and nothing more
 * (contract §6.1).
 *
 * Every figure in it is the storefront's **claim**: prices and totals are held
 * to the ERP's own calculation, and a payment status is never believed.
 */
readonly class WebsiteOrderSubmission
{
    /**
     * @param  array<string, string|null>  $shippingAddress
     * @param  array<string, string|null>  $billingAddress
     * @param  array<int, array{sku: string, quantity: int}>  $items
     * @param  array<int, Money>  $claimedUnitPrices  by line position
     * @param  array{subtotal: Money, discount: Money, shipping: Money, tax: Money, grand_total: Money}  $claimedTotals
     */
    public function __construct(
        public string $reference,
        public string $idempotencyKey,
        public WebsiteCustomerDetails $customer,
        public array $shippingAddress,
        public array $billingAddress,
        public array $items,
        public array $claimedUnitPrices,
        public array $claimedTotals,
        public string $paymentMethod,
        public ?string $gateway = null,
        public ?string $claimedPaymentStatus = null,
        public ?string $returnUrl = null,
        public ?string $couponCode = null,
        public ?string $customerNote = null,
    ) {}

    public function isCashOnDelivery(): bool
    {
        return $this->paymentMethod === 'cod';
    }

    /**
     * How long the stock is held: minutes for a payment, the confirmation
     * window for cash on delivery (contract §6.1.2).
     */
    public function reservationKind(): ReservationKind
    {
        return $this->isCashOnDelivery() ? ReservationKind::CashOnDelivery : ReservationKind::OnlinePayment;
    }

    /**
     * The order's own idempotency key: the storefront's, scoped to its website,
     * so two websites choosing the same key never meet.
     */
    public function orderKey(int $websiteId): string
    {
        return 'website-order:'.$websiteId.':'.$this->idempotencyKey;
    }
}
