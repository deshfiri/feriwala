<?php

namespace App\Domain\Billing\Exceptions;

use App\Integrations\Payment\Data\GatewayCapability;
use App\Integrations\Payment\Data\RefundResult;
use App\Support\Money\Money;
use RuntimeException;

/**
 * A refund that must not be made (§26.3).
 *
 * Distinct from a refund the provider declined, which is a
 * {@see RefundResult}. This is a refusal to ask
 * at all — the amount is not available, the payment never settled, the provider
 * has no such endpoint, or the wallet cannot carry the reversal.
 *
 * Every message is written to be shown to the administrator who tried, because
 * each of these is something a person can act on rather than a fault.
 */
class RefundRefused extends RuntimeException
{
    public static function notPositive(): self
    {
        return new self('A refund has to be for more than nothing.');
    }

    /**
     * Asked for, or already sent.
     *
     * D17 makes every refund an administrator's decision, so nothing is sent
     * until somebody has approved it — and a refund already processed is not
     * sent again.
     */
    public static function notApproved(string $status): self
    {
        return new self(
            "This refund is {$status}. Only an approved refund can be sent to the gateway."
        );
    }

    public static function notSettled(string $status): self
    {
        return new self(
            "This payment is {$status}. Only money that actually arrived can be sent back."
        );
    }

    public static function noGateway(): self
    {
        return new self('This payment never reached a gateway, so there is nothing to refund through.');
    }

    public static function exceedsRemaining(Money $requested, Money $remaining): self
    {
        return new self(sprintf(
            'Only %s of this payment is still refundable, and %s was requested.',
            $remaining->format(),
            $requested->format(),
        ));
    }

    public static function unsupported(string $gateway, GatewayCapability $capability): self
    {
        return new self(sprintf(
            '%s does not support %s, so this cannot be refunded through it.',
            $gateway,
            mb_strtolower($capability->label()),
        ));
    }

    public static function alreadyInProgress(): self
    {
        return new self('A refund for this payment was already being recorded. Check it before trying again.');
    }

    /**
     * The wallet cannot carry the reversal.
     *
     * The case this exists for: a top-up was credited, the account spent it, and
     * somebody now wants the top-up refunded. Taking it back would leave a
     * negative available balance nobody authorised — so the refund is refused
     * before the provider is asked rather than after the money has gone.
     */
    public static function walletCannotCover(Money $amount, Money $available): self
    {
        return new self(sprintf(
            'Refunding %s would overdraw this wallet, which has %s available. '
            .'The balance has to cover the reversal before the money can go back.',
            $amount->format(),
            $available->format(),
        ));
    }
}
