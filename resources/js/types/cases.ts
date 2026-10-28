import type { StatusTone } from '@/lib/status';
import type { Money } from '@/lib/money';

/**
 * What the server sends for return, exchange and damage screens (D35).
 *
 * Every figure arrives already worked out and formatted: nothing here is added,
 * compared or multiplied in the browser. Each action the staff workspace offers is
 * one the server will accept for this person at this stage.
 */

export type CaseKind = 'return' | 'exchange' | 'damage';

export type Responsibility = 'partner' | 'banij';

export type QueueRow = {
    id: string;
    kind: CaseKind;
    legacy: boolean;
    reference: string;
    order: { reference: string; id: string };
    exchange_order: string | null;
    partner: string;
    store: string | null;
    product: { name: string; variant: string | null; more: number };
    quantity: number;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    responsibility: Responsibility | null;
    submitted_at: string;
    next_action: { key: string; label: string } | null;
    waiting_for: string | null;
};

export type QueueCounts = Record<string, { total: number; actionable: number }>;

export type ConfirmationAmount = {
    key: string;
    amount: Money;
    direction: 'credit' | 'debit' | 'neutral';
};

export type ActionConfirmation = {
    items: { name: string; variant: string | null; quantity: number }[];
    responsible: Responsibility | null;
    responsible_label: string | null;
    amounts: ConfirmationAmount[];
    shows_disposition: boolean;
    reason_required: boolean;
    reason_minimum: number | null;
};

export type CaseAction = {
    key: string;
    label: string;
    url: string;
    tone: 'default' | 'primary' | 'danger';
    confirmation: ActionConfirmation;
    fields: Record<string, string>;
};

export type EvidenceFile = {
    id: string;
    name: string;
    purpose: string;
    purpose_label: string;
    uploaded_by: 'partner' | 'staff';
    note: string | null;
    mime_type: string;
    is_image: boolean;
    size_bytes: number;
    draft: boolean;
    /** The file was removed by an account erasure: the record stays, with nothing to open. */
    erased?: boolean;
    uploaded_at: string;
    preview_url: string | null;
    download_url: string | null;
};

export type LegacyEvidence = {
    reference: string;
    type: string | null;
    note: string | null;
};

export type TimelineEntry = {
    key: number;
    kind: 'status' | 'responsibility' | 'charge' | 'evidence';
    event: string;
    actor_type: 'partner' | 'staff' | 'customer' | 'system';
    actor: string | null;
    at: string;
    previous: string | null;
    next: string | null;
    note: string | null;
};

export type SummaryRow = {
    key: string;
    label: string;
    amount: Money | null;
    direction: 'credit' | 'debit' | 'neutral';
    bearer: Responsibility | null;
    bearer_label: string | null;
    note: string | null;
    covered_by_banij?: boolean;
};

export type LedgerRow = {
    id: string;
    label: string;
    kind: string;
    entry_type: 'charge' | 'reversal';
    bearer: Responsibility;
    bearer_label: string;
    amount: Money | null;
    direction: 'credit' | 'debit' | 'neutral';
    status: string;
    status_label: string;
    status_tone: string;
    reason: string | null;
    posted_at: string;
    covered_by_banij: boolean;
    ledger_url: string | null;
};

export type Financials = {
    summary: SummaryRow[];
    ledger: LedgerRow[];
    totals: {
        deducted: Money;
        outstanding: Money;
        reversed: Money;
        net_partner: Money;
        banij_cost: Money | null;
    };
};

export type CaseItem = {
    id: string;
    sku: string;
    name: string;
    variant: string | null;
    image: { url: string; alt: string | null } | null;
    sold: number;
    requested: number;
    approved: number | null;
    received: number | null;
    disposition: string | null;
    disposition_label: string | null;
    restored: boolean;
    note: string | null;
    unit_price: Money;
    supplier_backed: boolean;
    replaced_with: { sku: string; name: string; quantity: number } | null;
    condition_label?: string;
};

/** A reference to another record, with an address only where this reader may open it. */
export type LinkRef = {
    reference: string;
    url: string | null;
    status_label?: string | null;
};

export type CaseLinks = {
    order: LinkRef;
    exchange_order: LinkRef | null;
    previous_exchange: LinkRef | null;
    next_exchange: LinkRef | null;
};

/** Where an Exchange Order came from: the case that sent it and the Order it replaces goods of. */
export type ExchangeOrigin = {
    case: LinkRef;
    original_order: LinkRef | null;
};

export type AdminCase = {
    kind: CaseKind;
    id: string;
    reference: string;
    legacy: boolean;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    responsibility: Responsibility | null;
    responsibility_label: string | null;
    initiated_by: string | null;
    reason_label?: string;
    source_label?: string;
    description: string | null;
    created_at: string;
    decided_at?: string | null;
    received_at: string | null;
    inspected_at: string | null;
    order: LinkRef & { id: string };
    exchange_order?: (LinkRef & { number: string | null }) | null;
    previous_exchange?: LinkRef | null;
    next_exchange?: LinkRef | null;
    return?: { reference: string; url: string } | null;
    partner: string;
    store: string | null;
    cash_on_delivery?: boolean;
    remains_with_customer?: boolean;
    next_action: { key: string; label: string } | null;
    waiting_for: string | null;
    legacy_url?: string | null;
};

export type InspectionPanel = {
    recorded: boolean;
    outcome: string | null;
    note: string | null;
    inspected_at: string | null;
    dispositions: { value: string; label: string }[];
    warehouses: { id: string; label: string }[];
    cause_label?: string | null;
    recorded_dispositions?: { label: string; quantity: number }[];
    needs_warehouse?: boolean;
    causes?: { value: string; label: string }[];
    conditions?: { value: string; label: string }[];
    valuation?: {
        basis_label: string;
        maximum: Money;
        approved: Money | null;
        write_off_reason: string | null;
    };
};

export type AdminCaseProps = {
    case: AdminCase;
    items: CaseItem[];
    evidence: EvidenceFile[];
    legacy_evidence: LegacyEvidence[];
    inspection: InspectionPanel;
    financials: Financials;
    linked: {
        damages: { reference: string; status_label: string; url: string }[];
    };
    delivery?: {
        status: string;
        status_label: string;
        quantity: number;
        tracking_number: string | null;
        courier: string | null;
        requested_at: string;
        dispatched_at: string | null;
        received_at: string | null;
    } | null;
    couriers?: { code: string; name: string }[];
    storage_state?: string;
    timeline: TimelineEntry[];
    actions: CaseAction[];
    evidence_purposes: { value: string; label: string }[];
    evidence_url: string;
    open_damage_url: string | null;
    evidence_limits: {
        max_bytes: number;
        max_per_case: number;
        mime_types: string[];
    };
    warnings: { key: string; text: string }[];
};

export type PartnerCaseRow = {
    id: string;
    kind: 'return' | 'exchange';
    kind_label: string;
    reference: string;
    order: { reference: string; id: string };
    exchange_order: { reference: string; id: string } | null;
    product: { name: string; variant: string | null; more: number } | null;
    quantity: number;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    responsibility: Responsibility | null;
    responsibility_label: string | null;
    charge: Money | null;
    charge_covered_by_banij: boolean;
    charge_is_final: boolean;
    refund: Money | null;
    updated_at: string;
};

export type PartnerCaseDetail = {
    case: PartnerCaseRow & {
        reason_label: string;
        description: string | null;
        created_at: string;
        next_step: { key: string; label: string; action_needed: boolean };
        can_cancel: boolean;
        links: CaseLinks;
        legacy: boolean;
    };
    items: {
        id: string;
        name: string;
        variant: string | null;
        sku: string;
        image: { url: string; alt: string | null } | null;
        requested: number;
        approved: number | null;
        received: number | null;
        replaced_with: { name: string; quantity: number } | null;
    }[];
    evidence: EvidenceFile[];
    legacy_evidence: LegacyEvidence[];
    financials: Financials;
    refund: {
        amount: Money | null;
        state_label: string;
        refunded_at: string | null;
        is_a_matter_for_you: boolean;
    };
    timeline: TimelineEntry[];
};

export type EstimatePreview = {
    type: 'return' | 'exchange';
    original_delivery: Money;
    return_charge: Money;
    exchange_delivery: Money | null;
    estimated_total: Money;
    exchange: {
        lines: {
            replaces_sku: string;
            sku: string;
            name: string;
            quantity: number;
            same_product: boolean;
            unit_price: Money;
            difference: Money;
        }[];
        additional_due: Money;
        refund_due: Money;
        delivery: Money;
    } | null;
};

export type Eligibility = {
    eligible: boolean;
    refusal: string | null;
    window_closes_at: string | null;
    lines: {
        id: string;
        sku: string;
        name: string;
        variant: string | null;
        sold: number;
        returned: number;
        returnable: number;
        unit_price: Money;
    }[];
};

export type PartnerDamageRow = {
    id: string;
    reference: string;
    order_number: string;
    product: { sku: string; name: string; variation: string | null };
    image: { url: string; alt: string | null } | null;
    quantity: number;
    cause: string | null;
    cause_label: string | null;
    approved_loss: Money | null;
    responsibility: Responsibility | null;
    responsibility_label: string | null;
    storage_state: string;
    delivery_state: string;
    status: string;
    status_label: string;
    updated_at: string;
};
