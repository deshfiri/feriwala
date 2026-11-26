<?php

namespace App\Domain\Order\Data;

/**
 * Everything staff asked to change about an order in one go, from the Order
 * Details editor (D38, amended 2026-11-26): who it is for and where it goes, and —
 * for a Storefront order — its lines, prices, discount and delivery charge.
 *
 * Amounts arrive as the exact decimal strings typed; nothing here is a price
 * until the action has priced it.
 */
final readonly class OrderAmendment
{
    /**
     * @param  list<array{id: string|null, sku: string|null, quantity: int, unit_price: string|null}>|null  $lines  every line the order should have afterwards; null leaves the lines alone
     * @param  string|null  $discount  the order discount; null leaves it alone
     * @param  string|null  $delivery  the delivery charge; null leaves it alone
     */
    public function __construct(
        public OrderDetailsRevision $details,
        public ?array $lines,
        public ?string $discount,
        public ?string $delivery,
        public bool $passwordConfirmed = false,
        public bool $twoFactorEnabled = false,
    ) {}

    public function changesMoney(): bool
    {
        return $this->lines !== null || $this->discount !== null || $this->delivery !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            ...$this->details->toSnapshot(),
            'lines' => $this->lines,
            'discount' => $this->discount,
            'delivery' => $this->delivery,
        ];
    }
}
