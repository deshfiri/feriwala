<?php

namespace App\Domain\Cases\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * What a piece of evidence is for (D35): the moment in the case it was added at.
 *
 * `request` is a Partner's, with the request. The others are staff's: when the
 * parcel was received, when it was inspected, and when who bears the loss was
 * being decided.
 */
enum EvidencePurpose: string
{
    use HasTranslatedLabel;

    case Request = 'request';
    case Receipt = 'receipt';
    case Inspection = 'inspection';
    case ResponsibilityReview = 'responsibility_review';

    protected static function statusLabelGroup(): string
    {
        return 'case_evidence_purpose';
    }

    /**
     * @return list<self>
     */
    public static function forStaff(): array
    {
        return [self::Receipt, self::Inspection, self::ResponsibilityReview];
    }
}
