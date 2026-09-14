import type { Money } from '@/lib/money';

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

export type CheckoutSummary = {
    lines: CheckoutLine[];
    subtotal: Money;
    coupon: CheckoutCoupon | null;
    discount: Money;
    total: Money;
};
