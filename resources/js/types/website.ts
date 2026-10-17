import type { Money } from '@/lib/money';

/**
 * A website as a row in a list (§16.2).
 */
export type WebsiteSummary = {
    id: string;
    name: string;
    host: string;
    status: string;
    status_label: string;
    is_live: boolean;
    connection_health: string;
    last_synced_at: string | null;
    outstanding_charges: number;
    account: { id: string; name: string } | null;
    created_at: string;
};

/** The domain staff recorded against a website, as its owner reads it. */
export type WebsiteDomainRecord = {
    domain: string;
    status: string;
    status_label: string;
    registered_at: string | null;
    expires_at: string | null;
    days_remaining: number | null;
};

/** The hosting staff recorded against a website, as its owner reads it. */
export type WebsiteHostingRecord = {
    plan: string;
    status: string;
    status_label: string;
    started_at: string | null;
    expires_at: string | null;
    days_remaining: number | null;
};

/**
 * A row on the owner's own list: the website, and the domain and hosting
 * recorded for it — null until staff have recorded them.
 */
export type WebsiteOwnerSummary = WebsiteSummary & {
    domain_record: WebsiteDomainRecord | null;
    hosting_record: WebsiteHostingRecord | null;
};

/**
 * One side of a package-allocated website's term: its domain or its hosting.
 * Dates are the package-based period, counted from the confirmed purchase.
 */
export type WebsiteAllocationTerm = {
    included: boolean;
    starts_at: string;
    /** Null when the term does not end. */
    expires_at: string | null;
    /** Negative once the term has ended; null when it does not end. */
    days_remaining: number | null;
    /** True until the domain or hosting has actually been arranged. */
    package_based: boolean;
    /**
     * What the registrar or hosting provider actually holds, once arranged.
     * Separate from the package's period above and never shown as it.
     */
    provider: {
        /** The hosting plan; absent on the domain side. */
        plan?: string;
        started_at: string | null;
        expires_at: string | null;
    } | null;
};

/**
 * A website an account's package purchase entitles it to (§16.1, §16.2).
 */
export type WebsiteAllocationCard = {
    id: string;
    /** Which of the package's permitted websites this is, from 1. */
    slot: number;
    name: string;
    /** Null until the partner has set the website up. */
    website: { id: string; host: string; url: string } | null;
    domain_name: string | null;
    partner_name: string;
    package_name: string | null;
    status: 'pending_setup' | 'active' | 'suspended' | 'expired';
    status_label: string;
    status_tone: 'success' | 'warning' | 'danger' | 'info' | 'neutral';
    domain: WebsiteAllocationTerm;
    hosting: WebsiteAllocationTerm;
};

export type WebsiteCharge = {
    id: string;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    amount: Money;
    due_at: string;
    paid_at: string | null;
    period_start: string | null;
    period_end: string | null;
};

export type WebsiteDomainRow = {
    id: number;
    domain: string;
    status: string;
    status_label: string;
    registered_at: string | null;
    expires_at: string | null;
    days_remaining: number | null;
    auto_renew: boolean;
    fee: Money;
    /** Feriwala's own supplier — null for the partner (§16.3). */
    registrar: string | null;
    /** Whether the shared storefront may answer on it (D30 §3). */
    routing_status?: 'unverified' | 'verified' | 'active' | 'suspended';
    routing_status_label?: string;
    serves?: boolean;
    verified_at?: string | null;
    /** How to prove control, and the last check — staff only. */
    verification?: {
        record_name: string;
        record_value: string;
        checked_at: string | null;
        error: string | null;
    } | null;
    tls_authorized_at?: string | null;
};

export type WebsiteHostingRow = {
    id: number;
    plan: string;
    status: string;
    status_label: string;
    started_at: string | null;
    expires_at: string | null;
    days_remaining: number | null;
    auto_renew: boolean;
    fee: Money;
    /** Feriwala's own supplier — null for the partner (§16.3). */
    provider: string | null;
};

export type WebsiteStatusEntry = {
    id: number;
    previous_status: string | null;
    new_status: string;
    new_status_label: string;
    changed_at: string;
    note: string | null;
    /** Platform-only fields; null on a partner's own screen (§16.4). */
    source: string | null;
    reason: string | null;
    internal_note: string | null;
    changed_by: string | null;
};

/**
 * Everything one website's page shows.
 */
export type WebsiteDetail = WebsiteSummary & {
    subdomain: string;
    domain: string | null;
    tagline: string | null;
    about: string | null;
    theme: string;
    primary_color: string;
    secondary_color: string;
    logo_url: string | null;
    banner_url: string | null;
    banner_link_url?: string | null;
    contact: {
        email: string | null;
        phone: string | null;
        address: string | null;
    };
    charges_summary: {
        setup: Money;
        domain: Money;
        hosting: Money;
        required_deposit: Money;
        minimum_balance: Money;
    };
    lifecycle: {
        activated_at: string | null;
        expires_at: string | null;
        grace_ends_at: string | null;
        suspended_at: string | null;
        suspension_reason: string | null;
        maintenance_message: string | null;
    };
    connection: {
        health: string;
        health_label: string;
        api_connected_at: string | null;
        webhook_connected_at: string | null;
        last_synced_at: string | null;
    };
    charges: WebsiteCharge[];
    domains: WebsiteDomainRow[];
    hostings: WebsiteHostingRow[];
    history: WebsiteStatusEntry[];
};

/**
 * What the package allows, and how much of it is left (§8.1).
 */
export type WebsiteEntitlement = {
    allowed: boolean;
    /** The package website's domain or hosting period has run out. */
    expired: boolean;
    limit: number | null;
    used: number;
    remaining: number | null;
};

export type WebsiteWallet = {
    available: Money | null;
    currency: string;
};

/**
 * What a partner may charge for one product, and what they may change (§15.1).
 *
 * Sent so the screen can show the bounds and disable what is locked. The server
 * checks all of it again on every save — this is a convenience, never the rule.
 */
export type WebsitePricingTerms = {
    allows_user_pricing: boolean;
    minimum: Money | null;
    maximum: Money | null;
    suggested: Money | null;
    max_margin_percent: number | null;
    margin_ceiling: Money | null;
    locked_fields: string[];
};

/**
 * One central product, selected for one storefront (§15).
 */
export type WebsiteSelection = {
    id: string;
    product: {
        id: string;
        name: string;
        sku: string;
        image: string | null;
    };
    status: string;
    status_label: string;
    sync_status: string;
    sync_label: string;
    last_synced_at: string | null;
    sync_error: string | null;
    price: Money | null;
    promotional_price: Money | null;
    promotion_starts_at: string | null;
    promotion_ends_at: string | null;
    promotion_applies: boolean;
    promo_title: string | null;
    marketing_description: string | null;
    is_featured: boolean;
    display_order: number;
    category: { id: string; name: string } | null;
    terms: WebsitePricingTerms;
    published_at: string | null;
};

/**
 * A storefront's own arrangement of what it sells (§15).
 */
export type WebsiteCategoryRow = {
    id: string;
    name: string;
    slug: string;
    position: number;
    is_active: boolean;
    products_count: number;
};
