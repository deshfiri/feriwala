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

export type CatalogAbilities = {
    create: boolean;
    edit: boolean;
    delete: boolean;
};
