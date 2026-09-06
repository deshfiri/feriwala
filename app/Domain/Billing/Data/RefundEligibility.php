<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\Refundability;
use App\Support\Money\Money;

/**
 * Whether one component of a payment may be refunded, and why not (D17).
 *
 * Carries the reason rather than a bare boolean, because the answer is shown to
 * a person: an administrator looking at a refund request needs to know the
 * difference between "the registration fee is never refundable" and "this
 * account was activated three weeks ago", and a `false` tells them neither.
 *
 * `requiresApproval` is true whenever a refund is possible at all. D17 makes
 * every refund an administrative decision; nothing here is self-service.
 */
readonly class RefundEligibility
{
    public function __construct(
        public AllocationType $type,
        public Refundability $rule,
        public bool $isRefundable,
        public Money $refundableAmount,
        public ?string $reason = null,
    ) {}

    public static function refusedBy(
        AllocationType $type,
        Refundability $rule,
        Money $amount,
        string $reason,
    ): self {
        return new self(
            type: $type,
            rule: $rule,
            isRefundable: false,
            refundableAmount: Money::zero($amount->currency),
            reason: $reason,
        );
    }

    public static function allowed(AllocationType $type, Refundability $rule, Money $amount): self
    {
        return new self(
            type: $type,
            rule: $rule,
            isRefundable: true,
            refundableAmount: $amount,
        );
    }

    /**
     * Refunds are never automatic (D17). A component that may be refunded still
     * waits for an administrator.
     */
    public function requiresApproval(): bool
    {
        return $this->isRefundable;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'rule' => $this->rule->value,
            'rule_label' => $this->rule->label(),
            'is_refundable' => $this->isRefundable,
            'requires_approval' => $this->requiresApproval(),
            'refundable_amount' => $this->refundableAmount->jsonSerialize(),
            'reason' => $this->reason,
        ];
    }
}
