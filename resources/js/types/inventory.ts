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
