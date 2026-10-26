<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\OrderReturn;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;

/**
 * Close a case whose money is settled (D35).
 *
 * A case is complete when the customer's side is done — nothing owed back, or
 * what was owed has gone back — not when the Partner's charges have been
 * collected: an outstanding liability is the Partner's debt to Banij, recorded
 * and shown as owed, and it does not hold a customer's refund hostage.
 *
 * **Completed is immutable.** From here a case changes only through a
 * compensating action — a reversal and a new posting under a corrected
 * attribution — never by reopening it.
 *
 * Meant to be called inside the caller's transaction, with the case already
 * locked; it moves nothing unless the case is at the settlement stage and its
 * refund is done.
 */
class CompleteReturnCase
{
    public function __construct(
        protected AnnounceReturnStatus $announcements,
    ) {}

    /**
     * @return bool whether this call completed it
     */
    public function handle(OrderReturn $locked, ?int $actorId, OrderStatusChangeSource $source): bool
    {
        if ($locked->status !== ReturnStatus::SettlementPending) {
            return false;
        }

        if (! in_array($locked->refund_state, [ReturnRefundState::NotRequired, ReturnRefundState::Completed], true)) {
            return false;
        }

        $locked->forceFill(['completed_at' => CarbonImmutable::now()]);

        $locked->moveTo(
            ReturnStatus::Completed,
            new StatusChange(
                actorId: $actorId,
                reason: 'The case was settled.',
                publicNote: 'returns.notes.completed',
            ),
            $source,
        );

        $this->announcements->handle($locked->refresh()->load('items'));

        return true;
    }
}
