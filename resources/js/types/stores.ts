import type { StatusTone } from '@/lib/status';
import type { Money } from '@/lib/money';

/**
 * A partner's connection to one of their own stores on another platform (D29).
 *
 * Never carries a credential: the API key appears only as its last four
 * characters, and no secret is ever part of a page.
 */
export type ExternalStoreRow = {
    id: string;
    name: string;
    base_url: string;
    platform: string;
    platform_label: string;
    status: 'pending' | 'connected' | 'failing' | 'disconnected';
    status_label: string;
    status_tone: StatusTone;
    last_success_at: string | null;
    last_synced_at: string | null;
    last_error: string | null;
    orders_count: number;
};

export type ExternalStoreDetail = ExternalStoreRow & {
    api_key_hint: string | null;
    has_credentials: boolean;
    connected_at: string | null;
    last_failure_at: string | null;
    last_webhook_at: string | null;
    disconnected_at: string | null;
    consecutive_failures: number;
    webhook: {
        url: string;
        events: string[];
        signature_header: string;
        event_id_header: string;
    };
    history: {
        status: string;
        status_label: string;
        source: string;
        at: string;
        reason: string | null;
    }[];
    webhook_events: {
        event: string;
        state: string;
        received_at: string;
        duplicates: number;
        error: string | null;
    }[];
};

export type StoreAllowance = {
    allowed: boolean;
    limit: number | null;
    used: number;
    remaining: number | null;
};

/** One row of the unified orders list, from any source. */
export type BusinessOrderRow = {
    id: string;
    kind: 'website' | 'wholesale' | 'external';
    kind_label: string;
    number: string;
    secondary_number: string | null;
    store: string | null;
    customer: string | null;
    customer_phone: string | null;
    total: Money;
    payment_state: string | null;
    payment_label: string | null;
    payment_tone: StatusTone;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    fulfillment: { label: string; tone: StatusTone } | null;
    delivery: { label: string; tone: StatusTone } | null;
    placed_at: string;
    href: string;
};

/** One recorded entry in an order's history. */
export type TimelineEntry = {
    key: string;
    type: string;
    type_label: string;
    previous: string | null;
    previous_label: string | null;
    new: string | null;
    new_label: string | null;
    at: string;
    recorded_at: string | null;
    source: 'partner' | 'staff' | 'system' | 'external_store' | 'courier';
    source_label: string;
    actor: string | null;
    description: string | null;
    reference: string | null;
};

export type ExternalOrderDetail = {
    id: string;
    number: string;
    external_id: string;
    platform: string;
    store: {
        id: string;
        name: string;
        status: string;
        status_label: string;
        status_tone: StatusTone;
    };
    status: string;
    status_label: string;
    status_tone: StatusTone;
    payment_status: string | null;
    payment_label: string | null;
    payment_tone: StatusTone;
    payment_method: string | null;
    placed_at: string;
    source_updated_at: string | null;
    last_synced_at: string;
    customer: {
        id: string | null;
        name: string | null;
        phone: string | null;
        email: string | null;
    };
    shipping_address: Record<string, string | null> | null;
    billing_address: Record<string, string | null> | null;
    note: string | null;
    cancel_reason: string | null;
    coupon_code: string | null;
    totals: {
        subtotal: Money;
        discount: Money;
        shipping: Money;
        tax: Money;
        total: Money;
        paid: Money | null;
    };
    lines: {
        line: number;
        name: string;
        sku: string | null;
        variant: string | null;
        quantity: number;
        unit_price: Money;
        total: Money;
    }[];
};

export type BusinessCustomerRow = {
    id: string;
    kind: 'website' | 'external';
    kind_label: string;
    name: string | null;
    phone: string | null;
    email: string | null;
    store: string;
    orders_count: number;
    last_order_at: string | null;
    href: string;
};

export type BusinessCustomerDetail = {
    id: string;
    kind: 'website' | 'external';
    kind_label: string;
    name: string | null;
    phone: string | null;
    email: string | null;
    store: string;
    stats: {
        total: number;
        delivered: number;
        cancelled: number;
        returned: number;
    };
    orders: {
        id: string;
        number: string;
        status_label: string;
        status_tone: StatusTone;
        total: Money;
        placed_at: string;
        href: string;
    }[];
};
