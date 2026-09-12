<?php

namespace App\Domain\Wallet\Data;

use App\Domain\Billing\Enums\PaymentPurpose;
use App\Support\Money\Money;

/**
 * What a proposed top-up would actually do (§24, P2-19).
 *
 * Worked out on the server and handed to the screen already decided. The browser
 * renders these figures; it does not produce them, and it certainly does not
 * send a total back (§36.1).
 *
 * The split is the interesting part. Money paid into a wallet that is short of
 * its obligation does two different jobs: the first part closes the shortfall
 * and stays put, the rest is spendable. Saying so before somebody pays is the
 * difference between "top up ৳5,000" and "top up ৳5,000, of which ৳3,000 has to
 * stay in the wallet".
 */
readonly class TopUpPlan
{
    public function __construct(
        public Money $amount,
        /** The part that goes towards what the account must hold. */
        public Money $toObligation,
        /** The part that becomes spendable straight away. */
        public Money $toUsable,
        /** The least this account may pay in right now. */
        public Money $minimum,
        public PaymentPurpose $purpose,
    ) {}

    /**
     * Whether this payment closes the account's shortfall completely.
     */
    public function clearsShortfall(Money $shortfall): bool
    {
        return $this->amount->greaterThanOrEqualTo($shortfall);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount->jsonSerialize(),
            'to_obligation' => $this->toObligation->jsonSerialize(),
            'to_usable' => $this->toUsable->jsonSerialize(),
            'minimum' => $this->minimum->jsonSerialize(),
            'purpose' => $this->purpose->value,
            'purpose_label' => $this->purpose->label(),
        ];
    }
}
