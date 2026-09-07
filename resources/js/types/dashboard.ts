import type { ChartAxisTick, ChartSeries, ChartSlice } from '@/types/chart';
import type { StatusTone } from '@/lib/status';
import type { Money } from '@/lib/money';

/**
 * Chosen server-side, because a browser clock can say anything and "Good
 * morning" at nine at night is the kind of small wrongness that makes an
 * application feel unreliable.
 */
export type DashboardGreeting = {
    /** First name only — a greeting, not a form field. */
    name: string;
    period: 'morning' | 'afternoon' | 'evening';
};

/**
 * How the account stands today.
 *
 * Six statuses reach this page, not one: `AccountStatus::isActivated()` admits
 * a lapsed package, a low wallet balance and a temporary restriction alongside
 * plain `Active`, and §33.3 asks the dashboard to carry exactly those.
 */
export type DashboardStanding = {
    status: string;
    label: string;
    tone: StatusTone;
    /**
     * Whether the page should lead with the status. Decided server-side —
     * inferring it from the tone would put a business rule in the front end.
     */
    needsAttention: boolean;
    /** Null wherever there is nothing to click yet. */
    action: { label: string; href: string } | null;
};

/**
 * Settled spend over the trailing months. Deferred — arrives after the first
 * paint, so the page shows a skeleton in its place.
 */
export type DashboardSpend = {
    total: Money;
    months: number;
    series: ChartSeries[];
    /** Money axes carry their own labels; nothing is formatted in the browser. */
    ticks: ChartAxisTick[];
    breakdown: ChartSlice[];
};
