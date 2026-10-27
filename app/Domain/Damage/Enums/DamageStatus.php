<?php

namespace App\Domain\Damage\Enums;

use App\Support\StateMachine\TransitionableState;
use App\Support\Status\HasTranslatedLabel;

/**
 * Where a damage case has got to (D35).
 *
 * One stage at a time, nothing skipped, and no way back:
 *
 *   `reported → [awaiting_receipt → received →] under_inspection → liability_review →
 *    approved → financial_posting_pending → finalized → dispatch_requested →
 *    dispatched → received_by_partner`
 *
 * with `rejected` as the way a case ends without a loss. The bracketed stages
 * are for goods that come to Banij: a case opened from goods already back
 * starts at `received`, and one with no parcel at all (the item stays where it
 * is) goes straight to inspection.
 *
 * Nothing financial happens before `financial_posting_pending`, and the loss is
 * only posted by the move into `finalized`. A completed case is never reopened;
 * what it cost is corrected by a compensating entry, not by a status.
 */
enum DamageStatus: string implements TransitionableState
{
    use HasTranslatedLabel;

    case Reported = 'reported';
    case AwaitingReceipt = 'awaiting_receipt';
    case Received = 'received';
    case UnderInspection = 'under_inspection';
    case LiabilityReview = 'liability_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case FinancialPostingPending = 'financial_posting_pending';
    case Finalized = 'finalized';
    case DispatchRequested = 'dispatch_requested';
    case Dispatched = 'dispatched';
    case ReceivedByPartner = 'received_by_partner';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Reported => [self::AwaitingReceipt, self::Received, self::UnderInspection, self::Rejected],
            self::AwaitingReceipt => [self::Received, self::Rejected],
            self::Received => [self::UnderInspection],
            // An inspection that finds nothing damaged ends the case without a loss.
            self::UnderInspection => [self::LiabilityReview, self::Rejected],
            self::LiabilityReview => [self::Approved, self::Rejected],
            self::Approved => [self::FinancialPostingPending],
            self::FinancialPostingPending => [self::Finalized],
            self::Finalized => [self::DispatchRequested],
            self::DispatchRequested => [self::Dispatched],
            self::Dispatched => [self::ReceivedByPartner],

            default => [],
        };
    }

    protected static function statusLabelGroup(): string
    {
        return 'damage';
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::ReceivedByPartner], true);
    }

    /**
     * Whether a person has decided who bears the loss — from here the figures
     * are fixed and only a compensating entry changes what it cost.
     */
    public function isDecided(): bool
    {
        return in_array($this, [
            self::Approved, self::FinancialPostingPending, self::Finalized,
            self::DispatchRequested, self::Dispatched, self::ReceivedByPartner,
        ], true);
    }

    /**
     * Whether the loss has been posted: to Banij's accounting, or to the Partner.
     */
    public function isPosted(): bool
    {
        return in_array($this, [self::Finalized, self::DispatchRequested, self::Dispatched, self::ReceivedByPartner], true);
    }

    /**
     * Whether the damaged item is on its way to, or has reached, the Partner.
     */
    public function hasDispatchStarted(): bool
    {
        return in_array($this, [self::DispatchRequested, self::Dispatched, self::ReceivedByPartner], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Reported, self::LiabilityReview, self::FinancialPostingPending => 'warning',
            self::AwaitingReceipt, self::Received, self::UnderInspection, self::Approved,
            self::DispatchRequested, self::Dispatched => 'info',
            self::Finalized, self::ReceivedByPartner => 'success',
            self::Rejected => 'danger',
        };
    }
}
