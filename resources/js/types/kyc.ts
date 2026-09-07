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
    /**
     * The business behind the round, for the link to its full file (P1-79).
     * Null when this reader may not open that screen, so the link is absent
     * rather than leading to a refusal.
     */
    account_id: string | null;
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

/**
 * One scope rule on a requirement (§7.2).
 *
 * Both dimensions nullable: null matches anything, so a rule naming only a
 * country applies on every package there. `is_required` null means "use the
 * type's own setting" — the safe fallback.
 */
export type KycDocumentTypeScopeRow = {
    package: string | null;
    country: string | null;
    is_required: boolean | null;
};

/** A requirement in the catalogue, as the admin screen sees it (§7.2). */
export type KycDocumentTypeRow = {
    /** The public id. §34.2 keeps database ids out of the client. */
    id: string;
    key: string;
    name: string;
    instructions: string | null;
    is_required: boolean;
    is_active: boolean;
    is_archived: boolean;
    requires_file: boolean;
    requires_value: boolean;
    value_label: string | null;
    accepted_mime_types: string[];
    max_size_kb: number;
    sort_order: number;
    /** Rounds opened against it — why deletion may be unavailable. */
    used_by_rounds: number;
    scopes: KycDocumentTypeScopeRow[];
    can: { update: boolean; delete: boolean };
};

/** How far one requirement got in a round, for the applicant's history. */
export type KycRequirementStatus = 'supplied' | 'outstanding' | 'not_supplied';

/**
 * One round as the **applicant** sees it (§7.3, P1-27).
 *
 * Carries only what was written to them. The reviewer's internal note, the
 * internal reason behind a requested update, the reviewer's identity and every
 * file path are absent from the payload, not merely unrendered.
 */
export type KycHistoryRound = {
    id: string;
    round: number;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_editable: boolean;
    opened_at: string | null;
    submitted_at: string | null;
    reviewed_at: string | null;
    deadline_at: string | null;
    days_remaining: number | null;
    is_overdue: boolean;
    /** Whether an administrator asked for this round (§7.2). */
    was_requested: boolean;
    instructions: string | null;
    requirements: {
        key: string;
        name: string;
        instructions: string | null;
        is_required: boolean;
        status: KycRequirementStatus;
    }[];
    feedback: {
        outcome: string;
        feedback: string;
        at: string | null;
    }[];
};
