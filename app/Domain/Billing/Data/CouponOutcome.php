<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Models\Coupon;
use App\Support\Money\Money;

/**
 * Whether a coupon applies, and if not, why not (§9).
 *
 * The refusal reason is the point. "That code is not valid" gives somebody no
 * way to act; "this code ended on 30 June", "this code is for the Enterprise
 * package" and "you have already used this code" each tell them something
 * different and each has a different next step.
 *
 * The reason is a translation key rather than a sentence, so the same outcome
 * reads in the applicant's own language on the checkout screen.
 */
readonly class CouponOutcome
{
    private function __construct(
        public bool $isAccepted,
        public ?Coupon $coupon,
        public ?Money $discount,
        public ?string $reason,
    ) {}

    public static function accepted(Coupon $coupon, Money $discount): self
    {
        return new self(true, $coupon, $discount, null);
    }

    /**
     * @param  string  $reason  a `billing.coupons.refused.*` translation key
     */
    public static function refused(string $reason, ?Coupon $coupon = null): self
    {
        return new self(false, $coupon, null, $reason);
    }

    /**
     * The message an applicant reads.
     */
    public function message(): ?string
    {
        return $this->reason === null ? null : (string) __($this->reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'accepted' => $this->isAccepted,
            'code' => $this->coupon?->code,
            'name' => $this->coupon?->name,
            'discount' => $this->discount?->jsonSerialize(),
            'reason' => $this->message(),
        ];
    }
}
