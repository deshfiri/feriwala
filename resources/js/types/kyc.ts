import type { StatusTone } from '@/lib/status';

/** One row of the review queue (§7.3). */
export type KycQueueRow = {
    id: string;
    round: number;
    submitted_at: string | null;
    /** Whole days the applicant has been waiting, or null if never submitted. */
    waiting_days: number | null;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    applicant: {
        name: string | null;
        email: string | null;
        country: string | null;
    };
};

export type KycSubmissionSummary = {
    id: string;
    round: number;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    submitted_at: string | null;
    awaits_review: boolean;
    can_review: boolean;
};

export type KycApplicant = {
    id: string | null;
    name: string | null;
    email: string | null;
    mobile: string | null;
    country: string | null;
    status_label: string | null;
};

/**
 * A document as the reviewer sees it listed.
 *
 * Deliberately has no URL or path — §7.5 forbids a KYC file being reachable
 * without an authorisation check, so the page builds the route from the id and
 * the server checks again on every request.
 */
export type KycReviewDocument = {
    id: string;
    type: string | null;
    original_name: string;
    size_bytes: number;
    mime_type: string;
    can_open: boolean;
};

/** A typed detail, masked unless the viewer may open documents. */
export type KycReviewField = {
    type: string | null;
    value: string;
};

export type KycReviewHistoryEntry = {
    to_status: string;
    reviewer: string | null;
    reason: string | null;
    internal_note: string | null;
    user_visible_feedback: string | null;
    created_at: string;
};

export type KycDecisionOutcome = 'approve' | 'reject' | 'resubmit';
