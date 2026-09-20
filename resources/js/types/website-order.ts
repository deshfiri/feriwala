import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';

/** One order in a partner website's list (P6-8). */
export type WebsiteOrderRow = {
    id: string;
    reference: string;
    storefront_reference: string | null;
    customer: string | null;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    payment_state: string | null;
    /** `cod` or `online` — how the customer pays, decided by the ERP. */
    payment_method: string;
    total: Money;
    placed_at: string;
};

export type WebsiteOrderAddress = {
    line1?: string | null;
    line2?: string | null;
    city?: string | null;
    district?: string | null;
    postcode?: string | null;
    country?: string | null;
} | null;

/** One order's detail, as its shop's owner reads it. */
export type WebsiteOrderDetail = WebsiteOrderRow & {
    customer_details: {
        name: string | null;
        mobile: string | null;
        email: string | null;
        is_guest: boolean;
    };
    shipping_address: WebsiteOrderAddress;
    billing_address: WebsiteOrderAddress;
    customer_note: string | null;
    paid_at: string | null;
    cancelled_at: string | null;
    totals: {
        subtotal: Money;
        discount: Money;
        delivery: Money;
        tax: Money;
        total: Money;
    };
    lines: {
        id: string;
        name: string;
        sku: string;
        variant: string | null;
        quantity: number;
        unit_price: Money;
        total: Money;
        reservation: {
            status: string;
            status_label: string;
            expires_at: string;
        } | null;
    }[];
    payment: {
        state: string | null;
        method: string;
        expires_at: string | null;
        completed_at: string | null;
    } | null;
    /**
     * A cash-on-delivery order's confirmation, or null for any other.
     *
     * The code itself is never here, on the wire or in the props: it exists
     * only in the message the customer was sent (§6.2).
     */
    confirmation: {
        state: 'pending' | 'confirmed' | 'cancelled';
        expires_at: string | null;
        code_outstanding: boolean;
        resend_available_in: number;
    } | null;
    timeline: {
        status: string;
        status_label: string;
        at: string;
        note: string;
    }[];
};
