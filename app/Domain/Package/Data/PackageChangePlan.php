<?php

namespace App\Domain\Package\Data;

use App\Domain\Package\Enums\SubscriptionSource;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * What moving to another package would actually do (§8.3).
 *
 * Worked out once, server-side, and used by both the screen that offers the
 * change and the action that makes it — so what somebody is shown is what they
 * get. A page that recomputed any part of this would eventually disagree with
 * the thing it is describing, and the disagreement would be about money.
 */
readonly class PackageChangePlan
{
    public function __construct(
        /** Upgrade or Downgrade. Nothing else moves an account between packages. */
        public SubscriptionSource $direction,

        /** When the new term begins — now for an upgrade, term end for a downgrade. */
        public CarbonImmutable $effectiveFrom,

        /** When it ends. Null for a package that does not expire. */
        public ?CarbonImmutable $expiresAt,

        /** The unused value of the current term, credited against the change. */
        public Money $credit,

        /** The package or renewal fee being charged before the credit. */
        public Money $grossFee,

        /** Extra wallet deposit the larger package requires beyond the current one. */
        public Money $additionalDeposit,

        /** Limits the account is currently over, blocking a downgrade (D16). */
        public DowngradeAssessment $downgrade,

        /** The terms the new subscription would be taken on. */
        public SubscriptionTerms $terms,
    ) {}

    public function isUpgrade(): bool
    {
        return $this->direction === SubscriptionSource::Upgrade;
    }

    /**
     * What is payable now, after the credit and never below zero.
     *
     * A credit larger than the fee does not become a payout. Refunds are their
     * own workflow with their own approval (§27), and turning a package change
     * into one by arithmetic would route money out of the platform through a
     * screen that never asked anyone.
     */
    public function payable(): Money
    {
        $net = $this->grossFee->minus($this->credit);

        return $net->isNegative()
            ? Money::zero($this->grossFee->currency)
            : $net;
    }

    /**
     * Whether the change may go ahead at all.
     */
    public function isAllowed(): bool
    {
        return $this->isUpgrade() || $this->downgrade->isAllowed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'effective_from' => $this->effectiveFrom->toIso8601String(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'credit' => $this->credit->jsonSerialize(),
            'gross_fee' => $this->grossFee->jsonSerialize(),
            'additional_deposit' => $this->additionalDeposit->jsonSerialize(),
            'payable' => $this->payable()->jsonSerialize(),
            'is_allowed' => $this->isAllowed(),
            'downgrade' => $this->downgrade->toArray(),
            'package' => $this->terms->name,
            'validity_days' => $this->terms->validityDays,
        ];
    }
}
