import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';
import type { CheckoutAddress } from '@/types/wholesale';

/**
 * A wholesale order as its own account sees it (§10.2, P4-12).
 *
 * Every figure, status and state comes from the server. The page shows what the
 * order recorded and never works a total, a payment state or a stock state out
 * for itself.
 */
export type OrderStatusValue =
    | 'draft'
    | 'new'
    | 'pending_confirmation'
    | 'customer_verification_pending'
    | 'confirmed'
    | 'payment_pending'
    | 'paid'
    | 'processing'
    | 'stock_reserved'
    | 'ready_for_fulfillment'
    | 'picking'
    | 'packing'
    | 'ready_for_pickup'
    | 'courier_assigned'
    | 'shipped'
    | 'in_transit'
    | 'delivered'
    | 'completed'
    | 'delivery_failed'
    | 'on_hold'
    | 'cancelled'
    | 'return_requested'
    | 'return_approved'
    | 'returning'
    | 'returned'
    | 'refund_pending'
    | 'partially_refunded'
    | 'refunded';

export type OrderPaymentState =
    | 'awaiting'
    | 'confirming'
    | 'paid'
    | 'failed'
    | 'cancelled'
    | 'reconciliation'
    | 'refunded';

export type OrderStockState = 'held' | 'committed' | 'released' | 'attention';

/** Reporting only (D21, P4-14): never read by pricing, stock or eligibility. */
export type IntendedResaleChannel =
    | 'own_website'
    | 'social_media'
    | 'marketplace'
    | 'physical_shop'
    | 'wholesale'
    | 'other';

/** What the signed-in person may do to this order now, decided by the server. */
export type WholesaleOrderAbilities = {
    pay: boolean;
    cancel: boolean;
};

export type WholesaleOrderSummary = {
    id: string;
    reference: string;
    status: OrderStatusValue;
    status_tone: StatusTone;
    payment_state: OrderPaymentState | null;
    placed_at: string;
    total: Money;
    item_count: number;
};

export type WholesaleOrderLine = {
    id: string;
    name: string;
    sku: string;
    variant: string | null;
    quantity: number;
    unit_price: Money;
    subtotal: Money;
    discount: Money;
    tax: Money;
    total: Money;
};

export type WholesaleOrderDetail = WholesaleOrderSummary & {
    paid_at: string | null;
    cancelled_at: string | null;
    placed_by: string | null;
    lines: WholesaleOrderLine[];
    totals: {
        subtotal: Money;
        discount: Money;
        delivery: Money;
        tax: Money;
        tax_included: Money;
        total: Money;
    };
    coupon_code: string | null;
    customer_note: string | null;
    intended_resale_channel: IntendedResaleChannel | null;
    addresses: {
        billing: CheckoutAddress | null;
        shipping: CheckoutAddress | null;
    };
    payment: {
        reference: string;
        gateway: string | null;
        expires_at: string | null;
        window_open: boolean;
    } | null;
    stock: { state: OrderStockState; held_until: string | null };
    timeline: { status: OrderStatusValue; at: string; note: string | null }[];
    invoice: { id: string; number: string } | null;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
};
