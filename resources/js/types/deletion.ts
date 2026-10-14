import type { StatusTone } from '@/lib/status';

/**
 * A person's own latest request to have their account deleted. The note is the
 * one staff wrote for them; the reviewer's identity is never sent.
 */
export type OwnDeletionRequest = {
    id: string;
    status: 'pending' | 'rejected';
    reason: string;
    requested_at: string;
    decision_note: string | null;
};

/** What kind of account a request is about. */
export type DeletionKind = 'partner' | 'member' | 'platform_staff' | 'supplier';

/** One request in the staff queue (§5.3). */
export type DeletionQueueRow = {
    id: string;
    kind: DeletionKind;
    name: string | null;
    /** The person's email, or the Supplier's reference. */
    email: string | null;
    status: string;
    status_label: string;
    requested_at: string;
    waiting_days: number;
};

/** What an erasure would do, as counts and table names only. */
export type ErasureImpact = {
    deleted: Record<string, number>;
    anonymized: Record<string, number>;
    files: number;
    notes: string[];
};

export type DeletionStatusTone = StatusTone;
