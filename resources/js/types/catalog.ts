import type { Money } from '@/lib/money';

/**
 * Central catalogue shapes, as the administration screens receive them (§11).
 *
 * Figures arrive twice where a form edits them: the integer minor units the
 * field posts back, and the server's own rendering as {@see Money}. The page
 * shows the second and never formats the first itself (§36.1).
 */

export type CatalogOption = {
    value: string;
    label: string;
    /** False for a switched-off category or brand, which stays selectable. */
    is_available: boolean;
};

export type ProductRow = {
    id: string;
    name: string;
    sku: string;
    category: string;
    brand: string | null;
    wholesale_price: Money;
    status: string;
    updated_at: string;
};

export type ProductDetail = {
    id: string;
    name: string;
    slug: string;
    sku: string;
    barcode: string | null;
    short_description: string | null;
    description: string | null;
    category_id: string;
    brand_id: string | null;
    base_cost_minor: number;
    wholesale_price_minor: number;
    base_cost: Money;
    wholesale_price: Money;
    status: string;
    updated_at: string;
};

export type VariantRow = {
    id: string;
    sku: string;
    barcode: string | null;
    /** The combination in attribute order, e.g. "M / Navy". */
    label: string;
    values: { attribute: string; value: string }[];
    /** The override only; null means the product's figure applies. */
    wholesale_price_minor: number | null;
    base_cost_minor: number | null;
    /** The price that applies, override or not, rendered by the server. */
    wholesale_price: Money;
    overrides_price: boolean;
    is_active: boolean;
};

export type AttributeOption = {
    id: string;
    name: string;
    values: { id: string; value: string }[];
};

export type AttributeRow = {
    id: string;
    name: string;
    /** How many variations carry any of this attribute's values. */
    uses: number;
    values: { id: string; value: string; uses: number }[];
};

export type CatalogAbilities = {
    create: boolean;
    edit: boolean;
    delete: boolean;
};
