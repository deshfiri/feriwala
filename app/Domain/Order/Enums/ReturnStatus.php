<?php

namespace App\Domain\Order\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * Where a return has got to (§18.2, §26.3, contract §6.3, P6-12, D35).
 *
 * Two progressions share this enum, because the returns placed before D35 are
 * still in the table and must go on being read and finished the way they were:
 *
 * **Version 1** — the original three decisions, each by different people at
 * different times:
 *
 *   `requested → approved → received → refunded`
 *
 * **Version 2** — the case workflow, one stage at a time, nothing skipped:
 *
 *   `requested → approved → waiting_for_parcel → parcel_received →
 *    under_inspection → inspection_approved → settlement_pending → completed`
 *
 * with `rejected` (refusing the request), `inspection_rejected` (refusing the
 * goods on inspection) and `cancelled` as the three ways it ends early. None is
 * a way back into the flow: a refused case is asked for again as a new one, so
 * the refusal stays on the record as what was decided at the time.
 *
 * Money and stock move only from `inspection_approved` onwards: refunds,
 * balance deductions and restocking all follow a person's decision on goods
 * that have been seen.
 *
 * {@see transitionsFor()} is the version-aware map; {@see transitionsTo()} is
 * the union, which is all a state machine that does not know the version can
 * say. A return model asks the first.
 */
enum ReturnStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Received = 'received';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    case WaitingForParcel = 'waiting_for_parcel';
    case ParcelReceived = 'parcel_received';
    case UnderInspection = 'under_inspection';
    case InspectionApproved = 'inspection_approved';
    case InspectionRejected = 'inspection_rejected';
    case SettlementPending = 'settlement_pending';
    case Completed = 'completed';

    /**
     * Every move either progression makes. Use {@see transitionsFor()} when the
     * return's version is known.
     *
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return array_values(array_unique([
            ...$this->transitionsFor(1),
            ...$this->transitionsFor(2),
        ], SORT_REGULAR));
    }

    /**
     * Where a return of this workflow version may go from here.
     *
     * @return array<int, self>
     */
    public function transitionsFor(int $version): array
    {
        if ($version < 2) {
            return match ($this) {
                self::Requested => [self::Approved, self::Rejected, self::Cancelled],

                // Approved and nothing arrived: it may still be called off. Once
                // goods are in, it is a matter for the refund, not a cancellation.
                self::Approved => [self::Received, self::Cancelled],
                self::Received => [self::Refunded],

                default => [],
            };
        }

        return match ($this) {
            self::Requested => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::WaitingForParcel, self::Cancelled],
            self::WaitingForParcel => [self::ParcelReceived, self::Cancelled],
            self::ParcelReceived => [self::UnderInspection],
            self::UnderInspection => [self::InspectionApproved, self::InspectionRejected],
            self::InspectionApproved => [self::SettlementPending],
            self::SettlementPending => [self::Completed],

            default => [],
        };
    }

    protected static function statusLabelGroup(): string
    {
        return 'return';
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Rejected, self::Refunded, self::Cancelled, self::InspectionRejected, self::Completed,
        ], true);
    }

    /**
     * Whether goods may still be expected against this return.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::WaitingForParcel], true);
    }

    /**
     * Whether the parcel has reached Feriwala — from here nothing is simply
     * withdrawn; what is left is a decision about what came back.
     */
    public function hasParcel(): bool
    {
        return in_array($this, [
            self::Received, self::Refunded, self::ParcelReceived, self::UnderInspection,
            self::InspectionApproved, self::InspectionRejected, self::SettlementPending, self::Completed,
        ], true);
    }

    /**
     * Whether a person has judged the goods and approved them — the earliest
     * point at which stock or money may move for a version 2 case.
     */
    public function isInspectionApproved(): bool
    {
        return in_array($this, [self::InspectionApproved, self::SettlementPending, self::Completed], true);
    }

    /**
     * What a storefront, which knows only the six statuses of the frozen
     * contract (§6.3), is told. The new stages are additive and invisible to it.
     */
    public function contractValue(bool $refunded = false): string
    {
        return match ($this) {
            self::WaitingForParcel => self::Approved->value,
            self::ParcelReceived, self::UnderInspection, self::InspectionApproved, self::SettlementPending => self::Received->value,
            self::InspectionRejected => self::Rejected->value,
            self::Completed => $refunded ? self::Refunded->value : self::Received->value,
            default => $this->value,
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Approved, self::Received, self::WaitingForParcel, self::ParcelReceived,
            self::UnderInspection, self::InspectionApproved => 'info',
            self::Refunded, self::Completed => 'success',
            self::Rejected, self::Cancelled, self::InspectionRejected => 'danger',
            self::Requested, self::SettlementPending => 'warning',
        };
    }
}
