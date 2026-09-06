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
