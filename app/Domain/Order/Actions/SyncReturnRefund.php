<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\OrderReturn;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;

/**
 * Carry what happened to a refund back to the return it was for (§26.3,
 * contract §7.1, P6-12).
 *
 * The refund's own status is the truth about the money; this keeps the return
 * saying the same thing, so the partner, the customer and staff are never told
 * two different stories. Run from the refund request itself whenever its status
 * moves — by a decision, by the gateway's answer, or by the sweep that asks a
 * gateway later — so there is one place it happens, whatever moved it.
 *
 *   - **Processed**: the money went back. The return is refunded, and the shop
 *     hears `refund.completed`.
 *   - **Failed**: the gateway said no. It can be sent again under the same
 *     decision, and the return says so rather than pretending.
 *   - **Rejected**: an administrator refused the money. Somebody has to settle
 *     what the customer is owed for goods already taken back, so it is marked
 *     for manual review with the refusal as its note.
 *
 * Idempotent: a move already reflected on the return changes nothing.
 */
class SyncReturnRefund
{
    public function __construct(
        protected AnnounceReturnStatus $announcements,
    ) {}

    public function handle(RefundRequest $refund): void
    {
        /** @var OrderReturn|null $return */
        $return = OrderReturn::query()->where('refund_request_id', $refund->id)->lockForUpdate()->first();

        if ($return === null) {
            return;
        }

        $state = match ($refund->status) {
            RefundStatus::Processed => ReturnRefundState::Completed,
            RefundStatus::Failed => ReturnRefundState::Failed,
            RefundStatus::Rejected => ReturnRefundState::ManualReview,
            default => ReturnRefundState::Pending,
        };

        if ($return->refund_state === $state) {
            return;
        }

        $return->forceFill([
            'refund_state' => $state,
            'refund_note' => match ($state) {
                ReturnRefundState::Failed => $refund->failure_reason,
                ReturnRefundState::ManualReview => 'The refund request was refused: '.($refund->decision_note ?? 'no reason recorded').'. A person settles what is owed.',
                default => $return->refund_note,
            },
        ]);

        if ($state === ReturnRefundState::Completed && $return->status === ReturnStatus::Received) {
            $return->forceFill(['refunded_at' => $refund->processed_at ?? CarbonImmutable::now()]);

            $return->moveTo(
                ReturnStatus::Refunded,
                new StatusChange(
                    actorId: $refund->decided_by,
                    reason: 'The refund was confirmed by the gateway.',
                    publicNote: 'returns.notes.refunded',
                ),
                OrderStatusChangeSource::PaymentGateway,
            );
        } else {
            $return->save();
        }

        $this->announcements->handle($return->refresh()->load('items'));
    }
}
