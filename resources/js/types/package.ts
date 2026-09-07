import type { StatusTone } from '@/lib/status';

import type { Money } from '@/lib/money';

/** A charge attached to a package (§8.1, §16.2). */
export type PackageChargeRow = {
    charge_type: string;
    amount_minor: number;
    frequency: string;
};

/**
 * What stands in the way of retiring a package (§8.1).
 *
 * Sent with the row rather than discovered on submit: a guard an administrator
 * only meets when they press the button is one they meet after writing a change
 * they now have to undo.
 */
export type PackageBlockers = {
    kyc_requirements?: string[];
    subscriptions?: string[];
};

/** One package in the admin catalogue (§8.1). */
export type PackageRow = {
    /** The slug. §34.2 keeps database ids out of the client. */
    id: string;
    name: string;
    short_description: string | null;
    description: string | null;

    /** Formatted server-side, for display. */
    fee: Money;

    // Minor units throughout, for the form. Money is never a float (D4, §36.1).
    fee_minor: number;
    registration_fee_minor: number | null;
    renewal_fee_minor: number | null;
    required_deposit_minor: number;
    minimum_balance_minor: number;
    currency_code: string;

    validity_days: number | null;
    renewal_frequency: string | null;
    grace_period_days: number | null;

    available_from: string | null;
    available_until: string | null;

    is_active: boolean;
    is_public: boolean;
    is_archived: boolean;
    sort_order: number;

    subscriptions_count: number;

    /** Raw stored values, keyed by PackageFeature. */
    features: Record<string, string | null>;
    charges: PackageChargeRow[];
    blockers: PackageBlockers;

    can: { update: boolean; archive: boolean };
};

/** One entitlement a package may set. */
export type PackageFeatureDefinition = {
    key: string;
    label: string;
    type: 'boolean' | 'limit' | 'text';
};

/**
 * An account's own subscription (§8.2, §8.4).
 *
 * Built from the terms captured at purchase, not the package as it stands
 * today — a limit shown here is the limit this account is actually held to.
 */
export type AccountSubscriptionRow = {
    id: string;
    /** The package name as it was sold, not as it may since have been renamed. */
    package: string | null;

    status: string;
    status_label: string;
    status_tone: StatusTone;
    /** Whether this state still grants the package's features (§8.4). */
    entitles: boolean;

    source: string;
    source_label: string;

    started_at: string | null;
    expires_at: string | null;
    grace_ends_at: string | null;
    cancelled_at: string | null;

    /** Whole days until the term ends; negative once it has. */
    days_remaining: number | null;
    in_grace_period: boolean;

    paid: Money | null;
    renewal_fee: Money | null;
    renewal_frequency: string | null;

    /** Only on the current term — the history rows omit it. */
    features?: {
        key: string;
        label: string;
        type: 'boolean' | 'limit' | 'text';
        /** Null on a limit means unlimited; zero means none at all (§8.1). */
        value: boolean | number | string | null;
    }[];
};
