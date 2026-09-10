import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';

/**
 * The wire shapes of a wallet and its statement (§23, §33.7).
 *
 * Every figure arrives already worked out and already formatted. Nothing here is
 * a number the browser may add up: §36.1 puts the arithmetic on the server, and
 * a second opinion about a balance is exactly what a ledger exists to prevent.
 */

/**
 * The buckets §33.7 asks a wallet screen to tell apart.
 *
 * `usable` and `available_for_withdrawal` are derived server-side and differ on
 * purpose — a required deposit may be spendable while never being withdrawable.
 */
export interface WalletBalances {
    currency: string;
    total: Money;
    usable: Money;
    available_for_withdrawal: Money;
    required_deposit: Money;
    reserved: Money;
    pending: Money;
    hold: Money;
    cod_receivable: Money;
    meets_required_deposit: boolean;
    shortfall: Money;
}

/**
 * One row of the statement.
 *
 * `debit`, `credit` and `balance_after` are null when nothing moved: a
 * reservation is not a debit of zero, it is not a debit at all, and a screen
 * that printed 0.00 would be claiming otherwise.
 *
 * `internal_note` is absent — not null — unless the reader may see it (§23.2).
 */
export interface WalletMovement {
    id: string;
    reference: string;
    type: string;
    type_label: string;
    direction: 'credit' | 'debit';
    description: string;
    source: string;
    amount: Money;
    debit: Money | null;
    credit: Money | null;
    balance_after: Money | null;
    entry_reference: string | null;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_correction: boolean;
    reason: string | null;
    at: string | null;
    internal_note?: string | null;
}

/** One immutable ledger entry behind a movement (§23.2). */
export interface WalletLedgerEntry {
    id?: string;
    reference: string;
    debit: Money;
    credit: Money;
    balance_before: Money;
    balance_after: Money;
    /** The entry this one puts right, where it is a correction. */
    corrects?: string | null;
    at: string;
}

export interface WalletMovementDetail extends WalletMovement {
    payment_reference: string | null;
    currency: string;
    idempotency_key?: string | null;
    entries: WalletLedgerEntry[];
}

export interface WalletStatementFilters {
    type: string;
    status: string;
    direction: string;
    from: string;
    to: string;
    search: string;
}

export interface WalletFilterOption {
    value: string;
    label: string;
}

export interface WalletFilterOptions {
    types: WalletFilterOption[];
    statuses: WalletFilterOption[];
    directions: WalletFilterOption[];
}

/** A wallet as the administration lookup lists it. */
export interface WalletSummary {
    id: string;
    account: string | null;
    account_id: string | null;
    account_status: string | null;
    currency: string;
    total: Money;
    usable: Money;
    reserved: Money;
    hold: Money;
    meets_required_deposit: boolean;
    shortfall: Money;
}
