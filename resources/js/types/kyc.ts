import type { StatusTone } from '@/lib/status';

/** One row of the review queue (§7.3). */
export type KycQueueRow = {
    id: string;
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
/** What a slot currently holds. Never a path, a checksum or a value in full. */
export type KycBatchEvidence = {
    requirement: string;
    slot: string;
    kind: 'file' | 'value';
    document: string | null;
    name: string | null;
    size_bytes: number | null;
    live: boolean;
    value_preview: string | null;
    verdict: 'accepted' | 'rejected' | null;
    note: string | null;
};

/** One file, typed value or declaration a requirement is made of. */
export type KycBatchSlot = {
    key: string;
    label: string;
    instructions: string | null;
    required: boolean;
    evidence: 'file' | 'image' | 'pdf' | 'value' | 'declaration';
    mimes: string[];
    max_kb: number | null;
    value_label: string | null;
    sensitive: boolean;
    current: KycBatchEvidence | null;
};

export type KycBatchOption = {
    key: string;
    name: string;
    instructions: string | null;
    required: boolean;
    chosen: boolean;
    complete: boolean;
    provided: number;
    total: number;
    slots: KycBatchSlot[];
};

export type KycBatchGroup = {
    key: string;
    name: string;
    description: string | null;
    rule: 'all' | 'any' | 'min';
    /** The applicant chooses between options (any one, or a minimum). */
    choose: boolean;
    required: boolean;
    needed: number;
    done: number;
    offered: number;
    satisfied: boolean;
    options: KycBatchOption[];
};

export type KycBatchSelfie = {
    mode: 'optional' | 'required';
    required: boolean;
    satisfied: boolean;
    /** A picked file may stand in for the camera. */
    allows_upload: boolean;
    requirement: string;
    slot: KycBatchSlot;
};

/** A round on a KYC batch, as the applicant's form receives it. */
export type KycBatch = {
    name: string;
    description: string | null;
    version: number;
    editable: boolean;
    groups: KycBatchGroup[];
    selfie: KycBatchSelfie | null;
    complete: boolean;
    missing: string[];
};

/** What stands between a round on a batch and approval, for the reviewer. */
export type KycBatchReviewState = {
    /** Unmet groups, rejected evidence and evidence nobody has looked at. */
    problems: string[];
    /** The rejected slots as the applicant will be told. */
    corrections: string[];
    awaits_review: boolean;
    can_judge: boolean;
    can_open_documents: boolean;
    /** May reveal a typed value in full; each reveal is recorded. */
    can_view_sensitive: boolean;
};

/** A round on a batch as the reviewer sees it: the applicant's payload plus the review state. */
export type KycBatchForReview = KycBatch & { review: KycBatchReviewState };

export type KycBuilderSlot = {
    /** Null for a slot not saved yet. */
    id: string | null;
    label: string;
    label_bn: string | null;
    instructions: string | null;
    instructions_bn: string | null;
    required: boolean;
    evidence: 'file' | 'image' | 'pdf' | 'value' | 'declaration';
    mimes: string[];
    max_kb: number | null;
    value_label: string | null;
    value_label_bn: string | null;
    sensitive: boolean;
};

export type KycBuilderRequirement = {
    id: string | null;
    name: string;
    name_bn: string | null;
    instructions: string | null;
    instructions_bn: string | null;
    required: boolean;
    slots: KycBuilderSlot[];
};

export type KycBuilderGroup = {
    id: string | null;
    name: string;
    name_bn: string | null;
    description: string | null;
    description_bn: string | null;
    rule: 'all' | 'any' | 'min';
    minimum: number | null;
    required: boolean;
    requirements: KycBuilderRequirement[];
};

export type KycBuilderBatch = {
    id: string;
    key: string;
    name: string;
    name_bn: string | null;
    description: string | null;
    description_bn: string | null;
    audience: 'account' | 'supplier' | 'both';
    selfie_mode: 'disabled' | 'optional' | 'required';
    selfie_allows_upload: boolean;
    is_active: boolean;
    is_default: boolean;
    is_archived: boolean;
    published_version: number;
    has_unpublished_changes: boolean;
    rules: KycBatchRule[];
};

/** One row of the template list. */
export type KycBatchTemplateRow = {
    id: string;
    name: string;
    name_bn: string | null;
    audience: 'account' | 'supplier' | 'both';
    is_active: boolean;
    is_default: boolean;
    selfie_mode: 'disabled' | 'optional' | 'required';
    groups_count: number;
    published_version: number;
    has_unpublished_changes: boolean;
    outline: KycOutline;
};

/** One evidence slot as the outline draws it. */
export type KycOutlineSlot = {
    label: string;
    evidence: 'file' | 'image' | 'pdf' | 'value' | 'declaration';
    required: boolean;
    sensitive: boolean;
};

/** A batch as a tree: groups, the options in each, the evidence slots in each. */
export type KycOutline = {
    groups: {
        name: string;
        rule: 'all' | 'any' | 'min';
        minimum: number | null;
        required: boolean;
        options: { name: string; required: boolean; slots: KycOutlineSlot[] }[];
    }[];
};

export type KycReviewDocument = {
    id: string;
    type: string | null;
    /** The batch it was asked under, or null when it had none. */
    batch: string | null;
    original_name: string;
    size_bytes: number;
    mime_type: string;
    can_open: boolean;
};

/** A typed detail, masked unless the viewer may open documents. */
export type KycReviewField = {
    type: string | null;
    batch: string | null;
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

/** One rule saying who a batch applies to; null matches anything (§7.2). */
export type KycBatchRule = {
    package: string | null;
    country: string | null;
};

/**
 * A verification batch as the admin screen sees it (§7.2): a group of criteria
 * an applicant completes together, with the rules saying who is asked for it.
 */
export type KycBatchRow = {
    /** The public id. */
    id: string;
    key: string;
    audience: 'account' | 'supplier' | 'both';
    name: string;
    description: string | null;
    is_active: boolean;
    is_archived: boolean;
    sort_order: number;
    criteria_count: number;
    /** Empty means everyone. */
    rules: KycBatchRule[];
    can: { update: boolean; delete: boolean };
};

/** A requirement in the catalogue, as the admin screen sees it (§7.2). */
export type KycDocumentTypeRow = {
    /** The public id. §34.2 keeps database ids out of the client. */
    id: string;
    key: string;
    /** Who is asked for it: Client/Partner accounts, Suppliers, or both. */
    audience: 'account' | 'supplier' | 'both';
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
    /** The batch it sits in (its public id), or null for one made outside the admin screen. */
    batch: string | null;
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
