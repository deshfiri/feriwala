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
    limit: number | null;
    used: number;
    remaining: number | null;
};

export type WebsiteWallet = {
    available: Money | null;
    currency: string;
};
