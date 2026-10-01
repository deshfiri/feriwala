<?php

namespace App\Domain\Supplier\Enums;

use App\Domain\Supplier\Actions\RecordSupplierFulfilmentCommitment;
use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * A Supplier's commitment to fulfil one order line allocated to an
 * on_demand or pre_order offer (Supplier Bulk Product Listing batch,
 * correction 7).
 *
 * This is the capacity reservation itself, not a status report about one --
 * {@see RecordSupplierFulfilmentCommitment}
 * counts non-terminal rows (every case here except {@see Ready}, {@see
 * Failed} and {@see Cancelled}) against a bounded `fulfilment_capacity`, so
 * reaching a terminal state is what frees the capacity back up for the next
 * allocation.
 */
enum FulfilmentCommitmentStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    /** Just created; the Supplier has not yet confirmed they can still fulfil it. */
    case AwaitingConfirmation = 'awaiting_confirmation';

    /** The Supplier has confirmed this order line. */
    case Confirmed = 'confirmed';

    /** Actively being prepared for dispatch. */
    case Preparing = 'preparing';

    /** Ready -- fulfilment is done; this line no longer holds capacity. */
    case Ready = 'ready';

    /** The Supplier could not fulfil it after all. */
    case Failed = 'failed';

    /** Withdrawn -- the line was reallocated or the order cancelled. */
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::AwaitingConfirmation => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Preparing, self::Failed, self::Cancelled],
            self::Preparing => [self::Ready, self::Failed, self::Cancelled],
            self::Ready, self::Failed, self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }

    protected static function statusLabelGroup(): string
    {
        return 'supplier_fulfilment_commitment';
    }

    public function tone(): string
    {
        return match ($this) {
            self::AwaitingConfirmation => 'warning',
            self::Confirmed, self::Preparing => 'info',
            self::Ready => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'neutral',
        };
    }
}
