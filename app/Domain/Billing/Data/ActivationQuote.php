<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Models\Payment;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * The full breakdown a user sees before paying (§9).
 *
 * Immutable, and calculated entirely server-side. §36.1 requires server-side
 * calculation for money; the client renders this and never derives a figure of
 * its own, which is what stops a modified form from changing what is owed.
 *
 * Every component survives into {@see Payment}'s
 * allocations, so what was quoted is exactly what is stored, invoiced, reported,
 * and refunded against (§5.1, §9).
 */
class ActivationQuote
{
    /**
     * @param  array<int, QuoteLine>  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly Currency $currency,
    ) {}

    /**
     * The amount for one component, zero when absent.
     */
    public function amountFor(AllocationType $type): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->lines as $line) {
            if ($line->type === $type) {
                $total = $total->plus($line->amount);
            }
        }

        return $total;
    }

    /**
     * Fees before discount and tax.
     */
    public function subtotal(): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->lines as $line) {
            if (! $line->type->isDeduction()
                && $line->type !== AllocationType::Tax
                && $line->type !== AllocationType::GatewayCharge) {
                $total = $total->plus($line->amount);
            }
        }

        return $total;
    }

    /**
     * What the user actually pays.
     */
    public function total(): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->lines as $line) {
            $total = $total->plus($line->signedAmount());
        }

        return $total;
    }

    /**
     * The part of this payment that is Feriwala's revenue.
     *
     * Excludes the wallet deposit, which stays the partner's money, and the tax
     * and gateway charge, which are collected on someone else's behalf.
     */
    public function revenue(): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->lines as $line) {
            if ($line->type->isRevenue()) {
                $total = $total->plus($line->amount);
            }
        }

        return $total->minus($this->amountFor(AllocationType::Discount));
    }

    /**
     * Whether anything is actually payable.
     *
     * A fully discounted activation is legitimate — a promotion, or an
     * administratively granted package — and must not be sent to a gateway.
     */
    public function isPayable(): bool
    {
        return $this->total()->isPositive();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (QuoteLine $line) => $line->toArray(), $this->lines),
            'subtotal' => $this->subtotal()->jsonSerialize(),
            'total' => $this->total()->jsonSerialize(),
            'currency' => $this->currency->value,
            'is_payable' => $this->isPayable(),
        ];
    }
}
