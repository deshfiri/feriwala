import type { StatusTone } from '@/lib/status';

/** One row of the activation approval queue (§5.1, §44). */
export type ActivationQueueRow = {
    id: string;
    name: string;
    email: string;
    mobile: string | null;
    country: string | null;
    status_label: string;
    status_tone: StatusTone;
    /** When the account stopped waiting on itself and started waiting on us. */
    ready_since: string | null;
    waiting_days: number | null;
};

export type ActivationAccount = {
    id: string;
    name: string;
    email: string;
    mobile: string | null;
    country: string | null;
    registered_at: string | null;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_ready: boolean;
    /** Every unmet condition, not just the first. Empty when ready. */
    unmet: string[];

    /**
     * Three abilities, not one. Suspension carries a different permission from
     * approval (§5.3, D18) and the interface has to reflect that rather than
     * offering all three to whoever holds any of them.
     */
    can_approve: boolean;
    can_request_resubmission: boolean;
    can_suspend: boolean;
};

/** One of the three §5.1 conditions, with the evidence behind it. */
export type ActivationCondition = {
    key: string;
    label: string;
    met: boolean;
    detail: string | null;
};

export type AccountHistoryEntry = {
    to_status: string;
    changed_by: string | null;
    reason: string | null;
    internal_note: string | null;
    user_visible_note: string | null;
    created_at: string;
};

/**
 * The three outcomes of an activation review.
 *
 * There is no "rejected" status — §5.3 gives approval-pending exactly these
 * moves, and corrections and suspension mean genuinely different things. Each
 * posts to its own endpoint with its own authorization.
 */
export type ActivationOutcome = 'approve' | 'corrections' | 'suspend';
