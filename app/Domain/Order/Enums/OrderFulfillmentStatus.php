<?php

namespace App\Domain\Order\Enums;

use App\Domain\Order\Actions\AllocateOrderLineSource;
use App\Domain\Order\Actions\RollUpOrderStatus;
use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * Where an order stands in fulfilment (§18, §20, P6.B).
 *
 * A second axis beside {@see OrderStatus}: the order's own status answers
 * "where is this order in the business sense" (confirmed, paid, cancelled...),
 * this answers "how far has picking/packing/dispatch gotten" — the detail
 * {@see AllocateOrderLineSource::canReallocate()}
 * needs to refuse a reallocation after picking has started, and what
 * {@see RollUpOrderStatus} reads to decide when the
 * order's own status may advance.
 *
 * `SourceAllocationPending`/`SupplierConfirmationPending` exist because a line
 * can be waiting on a human decision — staff choosing a source, or a Supplier
 * confirming a non-ready-stock offer — before fulfilment work can start at
 * all. `PartiallyFulfilled` is a multi-line order where some lines are further
 * along than others, not a state any single line is ever in.
 *
 * The `order_fulfillment_statuses`/`order_fulfillment_status_transitions`
 * tables are seeded from these cases and {@see transitionsTo()}, the same
 * authority relationship {@see OrderStatus} has with its own tables.
 */
enum OrderFulfillmentStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    case PendingReview = 'pending_review';
    case SourceAllocationPending = 'source_allocation_pending';
    case SupplierConfirmationPending = 'supplier_confirmation_pending';
    case Processing = 'processing';
    case Picking = 'picking';
    case Packing = 'packing';
    case ReadyForDispatch = 'ready_for_dispatch';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::PendingReview => [self::SourceAllocationPending, self::OnHold, self::Cancelled],

            self::SourceAllocationPending => [self::SupplierConfirmationPending, self::Processing, self::OnHold, self::Cancelled],

            // A Supplier declines, or misses the confirmation deadline: the
            // line returns to staff review rather than corrupting anything
            // downstream (ExpireOverdueFulfilmentCommitments).
            self::SupplierConfirmationPending => [self::Processing, self::SourceAllocationPending, self::OnHold, self::Cancelled],

            self::Processing => [self::Picking, self::PartiallyFulfilled, self::OnHold, self::Cancelled],
            self::Picking => [self::Packing, self::OnHold],
            self::Packing => [self::ReadyForDispatch, self::OnHold],
            self::ReadyForDispatch => [self::Fulfilled, self::PartiallyFulfilled, self::OnHold],

            self::PartiallyFulfilled => [
                self::Processing, self::ReadyForDispatch, self::Fulfilled, self::OnHold, self::Cancelled,
            ],

            self::Fulfilled => [],

            self::OnHold => [
                self::SourceAllocationPending, self::SupplierConfirmationPending, self::Processing,
                self::Picking, self::Packing, self::ReadyForDispatch, self::PartiallyFulfilled, self::Cancelled,
            ],

            self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->transitionsTo() === [];
    }

    protected static function statusLabelGroup(): string
    {
        return 'order_fulfillment';
    }

    /**
     * The tone a status is shown in. Always beside its label, never instead of
     * it (§33.9).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Fulfilled => 'success',
            self::PartiallyFulfilled, self::SourceAllocationPending,
            self::SupplierConfirmationPending, self::OnHold => 'warning',
            self::Cancelled => 'danger',
            self::PendingReview => 'neutral',
            default => 'info',
        };
    }
}
