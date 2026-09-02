<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\AllocationType;
use App\Support\Money\Money;

/**
 * One line of a checkout breakdown (§9).
 */
class QuoteLine
{
    public function __construct(
        public readonly AllocationType $type,
        public readonly Money $amount,
        public readonly ?string $description = null,
    ) {}

    public function label(): string
    {
        return $this->description ?? $this->type->label();
    }

    /**
     * The amount as it affects the total — negative for a deduction.
     *
     * The stored amount stays positive; only this presentation flips it, so a
     * report summing discounts never has to guess at the sign.
     */
    public function signedAmount(): Money
    {
        return $this->type->isDeduction()
            ? $this->amount->negated()
            : $this->amount;
    }

    /**
     * @return array{type: string, label: string, amount: array<string, mixed>, is_deduction: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->label(),
            'amount' => $this->amount->jsonSerialize(),
            'is_deduction' => $this->type->isDeduction(),
        ];
    }
}
