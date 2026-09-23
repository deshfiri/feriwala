<?php

namespace App\Domain\Supplier\Enums;

use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;

/**
 * What moved in a Supplier's own ledger (D25, P13-23/P13-24).
 *
 * Mirrors {@see LedgerTransactionType}'s direction and
 * reason/actor rules, at the smaller scale this wallet needs. Two entries carry
 * more than one bucket at once rather than each needing a type of their own:
 *
 *   - {@see PayableReversalDebit} debits `total` by whatever is available and
 *     raises `recovery` by whatever is not (P13-25's settlement-side clawback),
 *     in one entry rather than two — a partial reversal that could only ever be
 *     "all debit" or "all recovery" would not fit the case that is genuinely
 *     both.
 *   - {@see WithdrawalPaidDebit} debits `total` and lowers `reserved` together,
 *     because a paid withdrawal both leaves the wallet and stops being held.
 */
enum SupplierLedgerEntryType: string
{
    /** A payable settled into the wallet (P13-23). */
    case SettlementCredit = 'settlement_credit';

    /** A settled payable was reversed by a return arriving afterwards (P13-25). */
    case PayableReversalDebit = 'payable_reversal_debit';

    /** A withdrawal request set money aside, spendable no longer (P13-24). */
    case WithdrawalReserved = 'withdrawal_reserved';

    /** A withdrawal was rejected or failed: its reservation is given back. */
    case WithdrawalReservationReleased = 'withdrawal_reservation_released';

    /** A withdrawal was paid: the reservation becomes a real debit. */
    case WithdrawalPaidDebit = 'withdrawal_paid_debit';

    /** A correction made by a person, not a workflow. */
    case ManualAdjustment = 'manual_adjustment';

    public function direction(): ?LedgerDirection
    {
        return match ($this) {
            self::SettlementCredit => LedgerDirection::Credit,
            self::PayableReversalDebit, self::WithdrawalPaidDebit => LedgerDirection::Debit,
            self::WithdrawalReserved, self::WithdrawalReservationReleased, self::ManualAdjustment => null,
        };
    }

    /**
     * Whether a person has to say why.
     *
     * A reversal and a released reservation both answer a question an auditor
     * will ask ("why did this shrink?"); a settlement or a paid withdrawal
     * explains itself through the payable or withdrawal it points at.
     */
    public function requiresReason(): bool
    {
        return match ($this) {
            self::PayableReversalDebit, self::WithdrawalReservationReleased, self::ManualAdjustment => true,
            default => false,
        };
    }

    public function requiresActor(): bool
    {
        return $this === self::ManualAdjustment;
    }

    public function label(): string
    {
        return match ($this) {
            self::SettlementCredit => 'Payable settlement',
            self::PayableReversalDebit => 'Reversal',
            self::WithdrawalReserved => 'Withdrawal reserved',
            self::WithdrawalReservationReleased => 'Withdrawal reservation released',
            self::WithdrawalPaidDebit => 'Withdrawal paid',
            self::ManualAdjustment => 'Manual adjustment',
        };
    }
}
