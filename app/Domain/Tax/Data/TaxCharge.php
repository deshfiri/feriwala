<?php

namespace App\Domain\Tax\Data;

use App\Domain\Tax\Enums\TaxMode;
use App\Domain\Tax\Models\TaxRate;
use App\Support\Money\Money;

/**
 * The tax on one amount at one rate (D19, §9).
 *
 * Carries all three figures — net, tax, gross — because which one is "the
 * price" depends on the mode, and a caller that has to work the others out from
 * one of them will eventually work them out differently from us. An invoice
 * needs the taxable base as well as the tax; a tax return needs both.
 *
 * The rate's code and basis points are copied in rather than referenced. When a
 * rate changes next year, an invoice issued today must still say what it said.
 */
readonly class TaxCharge
{
    public function __construct(
        public string $code,
        public string $label,
        public int $rateBasisPoints,
        public TaxMode $mode,
        /** The amount tax was charged on, exclusive of tax. */
        public Money $net,
        public Money $tax,
    ) {}

    /**
     * No tax, for a zero rate, an exempt account, or no rule at all.
     */
    public static function none(Money $amount): self
    {
        return new self(
            code: '',
            label: '',
            rateBasisPoints: 0,
            mode: TaxMode::Exclusive,
            net: $amount,
            tax: Money::zero($amount->currency),
        );
    }

    /**
     * Net plus tax — what is actually payable for this component.
     */
    public function gross(): Money
    {
        return $this->net->plus($this->tax);
    }

    public function isZero(): bool
    {
        return $this->tax->isZero();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'rate_basis_points' => $this->rateBasisPoints,
            'mode' => $this->mode->value,
            'net' => $this->net->jsonSerialize(),
            'tax' => $this->tax->jsonSerialize(),
            'gross' => $this->gross()->jsonSerialize(),
        ];
    }

    /**
     * The row written to `payment_tax_lines`.
     *
     * @return array<string, mixed>
     */
    public function toPaymentLine(): array
    {
        return [
            'tax_code' => $this->code,
            'label' => $this->label,
            'rate_basis_points' => $this->rateBasisPoints,
            'mode' => $this->mode->value,
            'taxable_amount_minor' => $this->net->minorUnits,
            'tax_amount_minor' => $this->tax->minorUnits,
            'currency_code' => $this->net->currency->value,
        ];
    }

    public function rate(): float
    {
        return $this->rateBasisPoints / TaxRate::BASIS_POINTS_PER_PERCENT;
    }
}
