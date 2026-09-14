import type { StatusTone } from '@/lib/status';

/**
 * Central stock, as the inventory screens receive it (§19).
 *
 * Counts only — no price ever travels with these rows.
 */
export const STOCK_BUCKETS = [
    'available',
    'reserved',
    'processing',
    'sold',
    'returned',
    'damaged',
] as const;

export type StockBucket = (typeof STOCK_BUCKETS)[number];

export type StockBuckets = Record<StockBucket, number>;

export type WarehouseOption = {
    id: string;
    code: string;
    name: string;
    is_active: boolean;
};

export type WarehouseRow = WarehouseOption & {
    address: string | null;
    priority: number;
    is_default: boolean;
    stock_items_count: number;
};

export type StockRow = {
    id: string;
    sku: string;
    product: { id: string; name: string };
    variant_id: string | null;
    warehouse: WarehouseOption;
    buckets: StockBuckets;
    updated_at: string;
};

/** One movement of stock, with every bucket's figure before and after. */
export type StockMovementRow = {
    id: string;
    type: string;
    type_label: string;
    from: StockBucket | null;
    to: StockBucket | null;
    quantity: number;
    before: StockBuckets;
    after: StockBuckets;
    reason: string | null;
    actor: string | null;
    occurred_at: string;
};

/** Units set aside for an order, and how that ended if it has. */
export type StockReservationRow = {
    id: string;
    reference: string;
    kind: 'online_payment' | 'cod';
    kind_label: string;
    status: 'active' | 'committed' | 'released' | 'expired';
    status_label: string;
    status_tone: StatusTone;
    quantity: number;
    expires_at: string;
    ended_at: string | null;
    release_reason: string | null;
};

/** A change a person may make by hand, and the buckets it moves between. */
export type AdjustmentKindOption = {
    value: string;
    label: string;
    from: StockBucket | null;
    to: StockBucket | null;
};

/** A stockable unit found for the hold dialog: a product, or one variation. */
export type StockUnit = {
    product: string;
    variant: string | null;
    sku: string;
    name: string;
};

export type StockFilters = {
    search: string | null;
    warehouse: string | null;
    state: string | null;
    sort: string | null;
    direction: 'asc' | 'desc';
};
