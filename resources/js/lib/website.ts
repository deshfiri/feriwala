import type { StatusTone } from '@/lib/status';

/**
 * The tone each website status reads in (§16.4, §33.9).
 *
 * Fourteen statuses onto the five tones the rest of the ERP already uses, so an
 * operator learns one colour vocabulary rather than one per module. Colour is
 * never the only carrier: `StatusPill` always renders the label beside it.
 *
 * The grouping is what the partner has to do about it, not how bad it sounds —
 * "grace period" and "low wallet balance" are warnings because the shop is
 * still serving and something can be done; "suspended" and "package expired"
 * are refusals that have already happened.
 */
export const websiteStatusTones: Record<string, StatusTone> = {
    setup_pending: 'info',
    deposit_pending: 'warning',
    development: 'info',
    api_connection_pending: 'info',
    active: 'success',
    low_wallet_balance: 'warning',
    grace_period: 'warning',
    domain_renewal_pending: 'warning',
    hosting_renewal_pending: 'warning',
    temporarily_disabled: 'danger',
    package_expired: 'danger',
    suspended: 'danger',
    maintenance: 'neutral',
    closed: 'neutral',
};

export function websiteStatusTone(status: string): StatusTone {
    return websiteStatusTones[status] ?? 'neutral';
}

/**
 * How a domain registration or hosting term reads.
 */
export const websiteServiceTones: Record<string, StatusTone> = {
    pending: 'info',
    active: 'success',
    expired: 'danger',
    cancelled: 'neutral',
};

export function websiteServiceTone(status: string): StatusTone {
    return websiteServiceTones[status] ?? 'neutral';
}

/**
 * How one charge reads: due is something to act on, the rest are settled.
 */
export const websiteChargeTones: Record<string, StatusTone> = {
    due: 'warning',
    paid: 'success',
    waived: 'neutral',
    cancelled: 'neutral',
};

export function websiteChargeTone(status: string): StatusTone {
    return websiteChargeTones[status] ?? 'neutral';
}

export const websiteHealthTones: Record<string, StatusTone> = {
    unknown: 'neutral',
    healthy: 'success',
    degraded: 'warning',
    failing: 'danger',
};

export function websiteHealthTone(health: string): StatusTone {
    return websiteHealthTones[health] ?? 'neutral';
}
