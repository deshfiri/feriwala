<?php

namespace App\Domain\Order\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * Where a charge stands (D35).
 *
 * The charge row never changes what it *is*; only this moves, and only the way
 * it is allowed to: an outstanding charge may be settled once the wallet can
 * cover it, and any charge may be answered by a reversal and so marked reversed.
 */
enum ReturnChargeStatus: string
{
    use HasTranslatedLabel;

    /** Borne by Banij: accounting only, no wallet involved. */
    case Recorded = 'recorded';

    /** Debited from the Partner's wallet. */
    case Posted = 'posted';

    /** Owed by the Partner; the wallet could not cover it, and it must not go negative. */
    case Outstanding = 'outstanding';

    /** An outstanding charge later collected from the wallet. */
    case Settled = 'settled';

    /** Answered by a reversal row; what it cost no longer stands. */
    case Reversed = 'reversed';

    public function tone(): string
    {
        return match ($this) {
            self::Posted, self::Settled, self::Recorded => 'success',
            self::Outstanding => 'warning',
            self::Reversed => 'neutral',
        };
    }

    /**
     * Whether this charge still stands as a cost — not answered by a reversal.
     */
    public function stands(): bool
    {
        return $this !== self::Reversed;
    }

    protected static function statusLabelGroup(): string
    {
        return 'return_charge_status';
    }
}
