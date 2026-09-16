<?php

namespace App\Domain\Website\Data;

use App\Support\Money\Money;

/**
 * What a partner may charge for one product, and what they may change (§15.1).
 *
 * The resolved answer, assembled from the administrator's rule and the
 * product's own selling bounds. One object rather than five loose values,
 * because every one of them is checked together on every write and a signature
 * that long is one where two arguments eventually get swapped.
 *
 * **Server-side only.** The figures travel to the browser so a partner can see
 * their bounds, but the refusal is decided here on every save — a price is
 * never allowed because the form said it was within range.
 */
readonly class WebsitePricingTerms
{
    /**
     * @param  array<int, string>  $lockedFields  §15.1 settings the partner may not touch
     */
    public function __construct(
        public bool $allowsUserPricing,
        public ?Money $minimum = null,
        public ?Money $maximum = null,
        public ?Money $suggested = null,
        public ?int $maxMarginPercent = null,
        public array $lockedFields = [],
    ) {}

    /**
     * Whether this setting is the administrator's rather than the partner's.
     */
    public function locks(string $field): bool
    {
        return in_array($field, $this->lockedFields, true);
    }

    /**
     * The ceiling the margin allows, where a floor and a margin both exist.
     *
     * Expressed against the **minimum selling price**, which is a figure the
     * administrator set for selling, never what Feriwala paid. A margin
     * computed from cost would mean putting cost where a partner could derive
     * it, and D12 keeps cost off every surface a partner reads.
     */
    public function marginCeiling(): ?Money
    {
        if ($this->maxMarginPercent === null || $this->minimum === null) {
            return null;
        }

        return Money::of(
            (int) floor($this->minimum->minorUnits * (100 + $this->maxMarginPercent) / 100),
            $this->minimum->currency,
        );
    }

    /**
     * Why this price is not allowed, or null when it is.
     *
     * Returns a reason key rather than a sentence: the caller turns it into a
     * message in the reader's language, and a rule object has no business
     * knowing which locale is being served.
     */
    public function refusalFor(Money $price): ?string
    {
        if (! $this->allowsUserPricing) {
            return 'pricing_not_allowed';
        }

        if (! $price->isPositive()) {
            return 'price_not_positive';
        }

        if ($this->minimum !== null && $price->lessThan($this->minimum)) {
            return 'below_minimum';
        }

        if ($this->maximum !== null && $price->greaterThan($this->maximum)) {
            return 'above_maximum';
        }

        $ceiling = $this->marginCeiling();

        if ($ceiling !== null && $price->greaterThan($ceiling)) {
            return 'above_margin';
        }

        return null;
    }

    /**
     * The terms as a screen reads them.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'allows_user_pricing' => $this->allowsUserPricing,
            'minimum' => $this->minimum?->jsonSerialize(),
            'maximum' => $this->maximum?->jsonSerialize(),
            'suggested' => $this->suggested?->jsonSerialize(),
            'max_margin_percent' => $this->maxMarginPercent,
            'margin_ceiling' => $this->marginCeiling()?->jsonSerialize(),
            'locked_fields' => $this->lockedFields,
        ];
    }
}
