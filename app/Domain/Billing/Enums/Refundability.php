<?php

namespace App\Domain\Billing\Enums;

/**
 * Whether a charge can be given back, and under what condition (D17).
 *
 * Three answers, not two. "Refundable" and "non-refundable" cannot express the
 * package fee's actual rule — refundable *before* activation, not after — and a
 * boolean would force that distinction into whatever code happened to be
 * asking, where it would eventually be got wrong.
 *
 * Approval is separate from possibility. Nothing here is refunded without an
 * administrator deciding: {@see RefundabilityPolicy} answers "may this be
 * refunded at all", never "refund it".
 */
enum Refundability: string
{
    /**
     * Never given back. The registration fee's default (D17) — it pays for
     * work already done at the moment of registering.
     */
    case Never = 'never';

    /**
     * Only while the account has not been activated.
     *
     * The package fee's default. Once a business is trading it has had what it
     * paid for, and unwinding that is a closure question rather than a refund
     * one (D18).
     */
    case BeforeActivation = 'before_activation';

    /** Refundable whenever an administrator approves it. */
    case Always = 'always';

    public function label(): string
    {
        return match ($this) {
            self::Never => 'Non-refundable',
            self::BeforeActivation => 'Refundable before activation',
            self::Always => 'Refundable',
        };
    }

    /**
     * The D17 default for a charge component.
     *
     * Defaults are deliberately the restrictive reading: a component nobody has
     * configured is non-refundable, so a misconfiguration refuses a refund that
     * a human can then grant, rather than paying out money nobody agreed to.
     *
     * The wallet deposit is the exception. It is the partner's own money held
     * on their behalf, never Feriwala's revenue, and §24.4 gives it its own
     * withdrawal rules — refusing to return it by default would be refusing to
     * hand back something that was never ours.
     */
    public static function defaultFor(AllocationType $type): self
    {
        return match ($type) {
            AllocationType::PackageFee,
            AllocationType::RenewalFee => self::BeforeActivation,

            AllocationType::WalletDeposit => self::Always,

            default => self::Never,
        };
    }
}
