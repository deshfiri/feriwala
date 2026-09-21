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

/** What of an order may still be sent back, and until when (P6-12). */
export type WebsiteOrderReturnable = {
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
    }[];
};

/**
 * One return, as the shop's owner and its customer see it: where it stands,
 * what was agreed and received, and where the money is — never where Feriwala
 * keeps the goods or what it did with them.
 */
export type WebsiteOrderReturn = {
    id: string;
    reference: string;
    status: string;
    reason: string;
    customer_note: string | null;
    requested_at: string;
    decision_note: string | null;
    lines: {
        id: string;
        sku: string;
        name: string;
        quantity: number;
        approved_quantity: number | null;
        received_quantity: number;
    }[];
    refund: {
        state: string;
        amount: Money | null;
        refunded_at: string | null;
    };
    timeline: { status: string; at: string; note: string }[];
    can_cancel: boolean;
};

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
