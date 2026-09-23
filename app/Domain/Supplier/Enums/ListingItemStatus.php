<?php

namespace App\Domain\Supplier\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * One proposed variation's own decision, independent of its sibling items on
 * the same listing (D25, P13-12).
 *
 * Not a {@see TransitionableState}: an item's status
 * is always set by the same review action that decides it, in one step, never
 * built up through a sequence of moves the way a listing or a Supplier is —
 * so there is no transition map to police.
 */
enum ListingItemStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case CorrectionRequired = 'correction_required';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::CorrectionRequired => 'Correction required',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::CorrectionRequired => 'warning',
            self::Pending => 'neutral',
        };
    }
}
