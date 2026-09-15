import type { Money } from '@/lib/money';
import type { IntendedResaleChannel } from '@/types/orders';

/**
 * The ERP wholesale cart, exactly as the server prices it (§14).
 *
 * Nothing here is worked out in the browser: every price, total, stock figure and
 * quantity rule comes back from the server on every request.
 */
export type CartProblem =
    | 'unavailable'
    | 'choose_variation'
    | 'variation_unavailable'
    | 'below_minimum'
    | 'above_maximum'
    | 'insufficient_stock';

export type CartLine = {
    id: string;
    product: {
        slug: string;
        name: string;
        sku: string;
        image: { url: string; alt: string | null } | null;
    };
    variant: { sku: string; label: string } | null;
    quantity: number;
    min_order_quantity: number;
    max_order_quantity: number | null;
    available: number;
    unit_price: Money | null;
    base_price: Money | null;
    line_total: Money | null;
    unit_price_seen: Money | null;
    price_changed: boolean;
    problems: CartProblem[];
    purchasable: boolean;
};

export type CartSummary = {
    lines: CartLine[];
    subtotal: Money;
    line_count: number;
    has_problems: boolean;
    has_price_changes: boolean;
    ready_for_checkout: boolean;
};

/** One line on the checkout summary, priced by the server on this request. */
export type CheckoutLine = {
    id: string;
    name: string;
    sku: string;
    variant: string | null;
    quantity: number;
    unit_price: Money;
    line_total: Money;
};

/** The code somebody entered, and whether it applies today and why not. */
export type CheckoutCoupon = {
    entered: string;
    accepted: boolean;
    code: string | null;
    name: string | null;
    discount: Money | null;
    reason: string | null;
};

/** Tax at one rate on this checkout, as the server's tax engine charged it. */
export type CheckoutTaxLine = {
    code: string;
    label: string;
    rate_basis_points: number;
    mode: 'exclusive' | 'inclusive';
    net: Money;
    tax: Money;
    gross: Money;
};

/** An address as the order would keep it. No identifier is ever sent. */
export type CheckoutAddress = {
    contact_name: string | null;
    contact_mobile: string | null;
    line_1: string | null;
    line_2: string | null;
    area: string | null;
    city: string | null;
    district: string | null;
    postcode: string | null;
    country: string | null;
};

export type CheckoutAddressType = 'billing' | 'shipping';

export type CheckoutPaymentMethod = { name: string; label: string };

/**
 * A confirmation on the cart. `stale` means the checkout priced now is no longer
 * the one confirmed, or its payment method can no longer take the payment.
 */
export type CheckoutConfirmation = {
    status: 'confirmed' | 'stale';
    payment_method: CheckoutPaymentMethod;
    confirmed_at: string;
    total: Money;
};

export type CheckoutSummary = {
    fingerprint: string;
    payment_methods: CheckoutPaymentMethod[];
    confirmation: CheckoutConfirmation | null;
    lines: CheckoutLine[];
    subtotal: Money;
    coupon: CheckoutCoupon | null;
    discount: Money;
    delivery: Money;
    tax: CheckoutTaxLine[];
    tax_added: Money;
    total: Money;
    addresses: Record<CheckoutAddressType, CheckoutAddress | null>;
    ready_to_confirm: boolean;
    /** An order from this cart already waiting for payment (P4-9). */
    pending_order: { id: string; reference: string } | null;
    resale_channels: IntendedResaleChannel[];
};
