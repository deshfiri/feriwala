import type { Money } from '@/lib/money';

/** The rule a commission was calculated under, from its own snapshot. */
export type AppliedRule = {
    type: 'fixed' | 'percentage';
    percent: string | null;
    amount: Money | null;
};

/** What a beneficiary may see of one of their commissions (D24). */
export type Earning = {
    id: string;
    level: number;
    is_joining_reward: boolean;
    amount: Money;
    rule: AppliedRule;
    status: string;
    status_label: string;
    skip_reason: string | null;
    capped: boolean;
    available_at: string;
    paid_at: string | null;
    created_at: string;
};

export type AccountRef = { id: string; name: string };

/** One commission as staff read it. */
export type CommissionRow = Earning & {
    event: string;
    trigger_label: string;
    source: AccountRef;
    beneficiary: AccountRef;
    reversal: {
        at: string;
        cause: string | null;
        cause_label: string | null;
        reason: string | null;
    } | null;
};

export type ChainAccount = {
    id: string;
    name: string;
    status: string;
    status_label: string;
};
