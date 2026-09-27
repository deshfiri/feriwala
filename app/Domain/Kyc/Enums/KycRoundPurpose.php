<?php

namespace App\Domain\Kyc\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * Why a KYC round exists.
 *
 * Three rounds can look identical on the wire — same status, same documents,
 * same reviewer — and mean entirely different things to the business reading
 * them. Onboarding is "we have not let you in yet". A correction is "fix this
 * one thing and we will". Re-verification is "you have been trading for a
 * year and we need to see these again", which is the only one that can carry
 * consequences for an account that is already live.
 *
 * Stored rather than derived. It could be inferred from whether the account
 * was active when the round opened, but that reads the wrong way round: the
 * reason a round was opened is a fact about the decision, not about the
 * account's status at some later moment when somebody asks.
 */
enum KycRoundPurpose: string
{
    use HasTranslatedLabel;

    /** The first pass, before the business has ever traded. */
    case Onboarding = 'onboarding';

    /** A reviewer sent an applicant back during onboarding (§7.3). */
    case Correction = 'correction';

    /** A trading business is asked to verify again (§7.2). */
    case Reverification = 'reverification';

    protected static function statusLabelGroup(): string
    {
        return 'kyc_purpose';
    }

    /**
     * Whether this round may carry consequences for a live account.
     *
     * Only re-verification can: the other two belong to an account that is
     * not trading yet, so there is nothing to restrict.
     */
    public function mayRestrict(): bool
    {
        return $this === self::Reverification;
    }
}
