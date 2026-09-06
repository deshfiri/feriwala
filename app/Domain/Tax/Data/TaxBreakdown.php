<?php

namespace App\Domain\Tax\Data;

use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * Tax on one transaction, grouped by rate (D19).
 *
 * An invoice has to read "VAT 15% on 5,000 — 750", per rate, and a tax return
 * needs the taxable base beside the tax. Neither is recoverable from a single
 * rolled-up total, which is why this exists alongside the quote's one Tax line
 * rather than instead of it.
 *
 * Charges at the same rate are merged, so a checkout with a registration fee and
 * a package fee both at the standard rate produces one line, not two — that is
 * how a VAT invoice reads.
 */
readonly class TaxBreakdown
{
    /**
     * @param  array<int, TaxCharge>  $charges
     */
    public function __construct(
        public array $charges,
        public Currency $currency,
    ) {}

    public static function empty(Currency $currency): self
    {
        return new self([], $currency);
    }

    /**
     * Merge charges by rate code, dropping the ones that came to nothing.
     *
     * A zero-rated line is deliberately dropped rather than kept at 0.00: it
     * carries no tax and no obligation, and a VAT invoice listing "0% — 0.00"
     * beside a real rate invites the reader to think something was missed.
     *
     * @param  array<int, TaxCharge>  $charges
     */
    public static function of(array $charges, Currency $currency): self
    {
        /** @var array<string, TaxCharge> $merged */
        $merged = [];

        foreach ($charges as $charge) {
            if ($charge->isZero()) {
                continue;
            }

            $key = $charge->code.'|'.$charge->rateBasisPoints.'|'.$charge->mode->value;

            $merged[$key] = isset($merged[$key])
                ? new TaxCharge(
                    code: $charge->code,
                    label: $charge->label,
                    rateBasisPoints: $charge->rateBasisPoints,
                    mode: $charge->mode,
                    net: $merged[$key]->net->plus($charge->net),
                    tax: $merged[$key]->tax->plus($charge->tax),
                )
                : $charge;
        }

        return new self(array_values($merged), $currency);
    }

    public function total(): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->charges as $charge) {
            $total = $total->plus($charge->tax);
        }

        return $total;
    }

    /**
     * Tax that increases what is payable — the exclusive charges.
     *
     * Kept apart from {@see includedTotal()} because adding inclusive tax to a
     * total would charge it twice: it is already inside the price the customer
     * was quoted. Both still appear on the invoice; only this one is added up.
     */
    public function addedTotal(): Money
    {
        return $this->totalWhere(fn (TaxCharge $charge) => ! $charge->mode->isInclusive());
    }

    /**
     * Tax already contained in the prices shown — the inclusive charges.
     *
     * Reported, never added. An invoice must still show it, and a tax return
     * still owes it.
     */
    public function includedTotal(): Money
    {
        return $this->totalWhere(fn (TaxCharge $charge) => $charge->mode->isInclusive());
    }

    /**
     * @param  callable(TaxCharge): bool  $keep
     */
    protected function totalWhere(callable $keep): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->charges as $charge) {
            if ($keep($charge)) {
                $total = $total->plus($charge->tax);
            }
        }

        return $total;
    }

    /**
     * The amount all of this tax was charged on.
     */
    public function taxableTotal(): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->charges as $charge) {
            $total = $total->plus($charge->net);
        }

        return $total;
    }

    public function isEmpty(): bool
    {
        return $this->charges === [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (TaxCharge $charge) => $charge->toArray(), $this->charges);
    }
}
