<?php

namespace App\Domain\Order\Data;

use App\Domain\Order\Enums\ReturnReason;

/**
 * A return as somebody asks for it — a customer through their shop, a partner,
 * or staff (contract §6.3, P6-12).
 *
 * A claim, not a decision. Everything in it is checked against the order before
 * anything is written: which lines exist, how many are left to send back, and
 * whether the order is in a state that allows any of it.
 */
final class ReturnSubmission
{
    /**
     * @param  array<int, array{sku: string, quantity: int}>  $lines  what is coming back
     * @param  array<int, string>|null  $evidence  references the customer supplied, never files
     */
    public function __construct(
        public readonly ReturnReason $reason,
        public readonly array $lines,
        public readonly ?string $customerNote = null,
        public readonly ?array $evidence = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    /**
     * The quantities asked for, by SKU, with anything unreadable dropped.
     *
     * @return array<string, int>
     */
    public function quantities(): array
    {
        $quantities = [];

        foreach ($this->lines as $line) {
            $sku = trim($line['sku']);
            $quantity = $line['quantity'];

            if ($sku === '' || $quantity <= 0) {
                continue;
            }

            $quantities[$sku] = ($quantities[$sku] ?? 0) + $quantity;
        }

        return $quantities;
    }
}
