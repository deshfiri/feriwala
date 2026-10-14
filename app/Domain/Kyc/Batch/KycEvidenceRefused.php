<?php

namespace App\Domain\Kyc\Batch;

use RuntimeException;

/**
 * Evidence the batch rules do not allow, refused before anything is stored (D30).
 *
 * The message is what the applicant is shown, in their language, and says how to
 * fix it.
 */
final class KycEvidenceRefused extends RuntimeException
{
    public static function legacyRound(): self
    {
        return new self(__('kyc.batch.refused.legacy_round'));
    }

    public static function locked(): self
    {
        return new self(__('kyc.batch.refused.locked'));
    }

    public static function unknownSlot(): self
    {
        return new self(__('kyc.batch.refused.unknown_slot'));
    }

    public static function wrongKind(): self
    {
        return new self(__('kyc.batch.refused.wrong_kind'));
    }

    public static function alreadyAccepted(): self
    {
        return new self(__('kyc.batch.refused.already_accepted'));
    }

    public static function optionLocked(): self
    {
        return new self(__('kyc.batch.refused.option_locked'));
    }

    public static function unknownOption(): self
    {
        return new self(__('kyc.batch.refused.unknown_option'));
    }

    public static function notChoosable(): self
    {
        return new self(__('kyc.batch.refused.not_choosable'));
    }

    public static function liveOnly(): self
    {
        return new self(__('kyc.batch.refused.live_only'));
    }

    /**
     * @param  array<int, string>  $allowed
     */
    public static function format(string $mime, array $allowed): self
    {
        return new self(__('kyc.batch.refused.format', ['mime' => $mime, 'allowed' => implode(', ', $allowed)]));
    }

    public static function tooLarge(int $sizeKb, int $limitKb): self
    {
        return new self(__('kyc.batch.refused.too_large', ['size' => $sizeKb, 'limit' => $limitKb]));
    }

    public static function emptyValue(): self
    {
        return new self(__('kyc.batch.refused.empty_value'));
    }
}
