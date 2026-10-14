<?php

namespace App\Domain\Kyc\Enums;

/**
 * How a verification group is satisfied (D30).
 *
 * The same three rules cover every case an administrator needs: Business
 * Verification is `All`, Identity Verification ("any one of NID, passport,
 * licence") is `Any`, and "any 2 of bank statement, utility bill, rental
 * agreement" is `Minimum`.
 */
enum KycCompletionRule: string
{
    /** Every required requirement in the group. */
    case All = 'all';

    /** At least one of the options. */
    case Any = 'any';

    /** At least `minimum_required` of the options. */
    case Minimum = 'min';

    /**
     * How many options must be complete for a group offering `$options`.
     * `All` has no count of its own: it is every requirement marked required.
     */
    public function needed(?int $minimum): ?int
    {
        return match ($this) {
            self::All => null,
            self::Any => 1,
            self::Minimum => $minimum,
        };
    }

    /**
     * Whether the applicant chooses between options (and so has a selection to
     * record) rather than completing them all.
     */
    public function choosesBetweenOptions(): bool
    {
        return $this !== self::All;
    }
}
