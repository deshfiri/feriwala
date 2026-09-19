<?php

namespace App\Domain\Referral\Enums;

/**
 * What took a commission back (§25.5, D24, P7-43).
 *
 * A refund is the qualifying payment coming back through the refund workflow.
 * A chargeback, fraud or activation rollback is a person deciding, with a
 * reason, because none of them arrives as a settled refund.
 */
enum ReversalCause: string
{
    case Refund = 'refund';
    case Chargeback = 'chargeback';
    case Fraud = 'fraud';
    case ActivationRollback = 'activation_rollback';
    case Manual = 'manual';

    /** An unpaid commission whose beneficiary closed before it was due. */
    case BeneficiaryClosed = 'beneficiary_closed';

    /**
     * The causes a person may choose.
     *
     * @return array<int, self>
     */
    public static function manual(): array
    {
        return [self::Chargeback, self::Fraud, self::ActivationRollback, self::Manual];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $cause) => $cause->value, self::cases());
    }
}
