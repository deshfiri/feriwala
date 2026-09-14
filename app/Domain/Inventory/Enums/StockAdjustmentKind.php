<?php

namespace App\Domain\Inventory\Enums;

/**
 * The changes a person may make to central stock by hand (§19, §19.1).
 *
 * Each names exactly which buckets it moves between, so an adjustment can never
 * put units somewhere the kind does not say: receiving never lands in reserved,
 * and a return is only restocked after inspection (§19.1). Reserving, committing
 * and selling are not here at all — those belong to orders, not to a person
 * with a form.
 */
enum StockAdjustmentKind: string
{
    /** Stock received into the warehouse. */
    case Receive = 'receive';

    /** Available stock that is no longer there — lost, miscounted, taken as a sample. */
    case Remove = 'remove';

    /** Available stock found damaged. */
    case Damage = 'damage';

    /** Damaged stock repaired and sellable again. */
    case Repair = 'repair';

    /** Damaged stock that cannot be sold, taken out of central stock. */
    case WriteOffDamaged = 'write_off_damaged';

    /** A returned unit that passed inspection, back on sale (§19.1). */
    case RestockReturn = 'restock_return';

    /** A returned unit that failed inspection. */
    case RejectReturn = 'reject_return';

    /**
     * The bucket units leave, or null when they arrive from outside.
     *
     * Not `from()`: a backed enum already has a static `from()`.
     */
    public function source(): ?StockBucket
    {
        return match ($this) {
            self::Receive => null,
            self::Remove, self::Damage => StockBucket::Available,
            self::Repair, self::WriteOffDamaged => StockBucket::Damaged,
            self::RestockReturn, self::RejectReturn => StockBucket::Returned,
        };
    }

    /**
     * The bucket units arrive in, or null when they leave central stock.
     */
    public function destination(): ?StockBucket
    {
        return match ($this) {
            self::Receive, self::Repair, self::RestockReturn => StockBucket::Available,
            self::Remove, self::WriteOffDamaged => null,
            self::Damage, self::RejectReturn => StockBucket::Damaged,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Receive => 'Receive stock',
            self::Remove => 'Remove available stock',
            self::Damage => 'Mark available stock damaged',
            self::Repair => 'Return repaired stock to available',
            self::WriteOffDamaged => 'Write off damaged stock',
            self::RestockReturn => 'Restock an inspected return',
            self::RejectReturn => 'Reject an inspected return as damaged',
        };
    }
}
