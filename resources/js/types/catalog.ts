import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';

/** A lifecycle move this person may make from the product's current status. */
export type ProductTransition = {
    value: string;
    tone: StatusTone;
    requires_reason: boolean;
};

/** One recorded status move, newest first. */
export type ProductStatusChangeRow = {
    id: number;
    from: string | null;
    to: string;
    to_tone: StatusTone;
    actor: string | null;
    reason: string | null;
    at: string;
};

/**
 * Central catalogue shapes, as the administration screens receive them (§11).
 *
 * Every figure arrives as the server's own {@see Money} rendering (D26): its
 * exact flat-Taka `amount` is what a form field edits and posts back exactly as
 * typed, and its `formatted` string is what the page shows. The page never
 * formats or scales a figure itself (§36.1).
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
    status_tone: StatusTone;
    is_featured: boolean;
    channels: ProductChannelState[];
    /** The listing image (position one), or null when there is none. */
    image_url: string | null;
    media_count: number;
    variants_count: number;
    updated_at: string;
};

/** The product list's filters, as the server accepted them. */
export type ProductListFilters = {
    status: string | null;
    category: string | null;
    brand: string | null;
    /** A channel status value, e.g. `wholesale_enabled`. */
    channel: string | null;
    featured: 'yes' | 'no' | null;
};

/** What the bulk bar may offer this person; the server checks each product. */
export type ProductBulkOptions = {
    max: number;
    transitions: { value: string; requires_reason: boolean }[];
    enable_channels: boolean;
    disable_channels: boolean;
    feature: boolean;
    /** Filing products under a category or brand (§11.3). */
    assign: boolean;
};

/** What one bulk request did, product by product. */
export type ProductBulkResult = {
    changed: number;
    unchanged: number;
    refused: {
        id: string;
        name: string | null;
        sku: string | null;
        reason: string;
    }[];
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
    base_cost: Money;
    wholesale_price: Money;
    meta_title: string | null;
    meta_description: string | null;
    meta_keywords: string | null;
    mpn: string | null;
    item_condition: 'new' | 'refurbished' | 'used';
    social_image_id: string | null;
    min_order_quantity: number;
    /** Null means no limit. */
    max_order_quantity: number | null;
    /** Selling-price guidance for partners; null means no bound. */
    suggested_selling_price: Money | null;
    minimum_selling_price: Money | null;
    maximum_selling_price: Money | null;
    status: string;
    status_tone: StatusTone;
    /** The first moment the product went live; never moved afterwards. */
    published_at: string | null;
    channels: ProductChannelState[];
    updated_at: string;
} & ProductLogisticsFields;

/**
 * Physical logistics and packaging, shared shape between a product and a
 * variant's own override (beta-critical batch, Commit 1). Weight in whole
 * grams, every dimension a decimal string of centimetres -- the server's own
 * canonical units, never converted or parsed here.
 */
export type ProductLogisticsFields = {
    net_weight_grams: number | null;
    shipping_weight_grams: number | null;
    length_cm: string | null;
    width_cm: string | null;
    height_cm: string | null;
    ships_by_box: boolean | null;
    pieces_per_box: number | null;
    box_weight_grams: number | null;
    box_length_cm: string | null;
    box_width_cm: string | null;
    box_height_cm: string | null;
    is_fragile: boolean | null;
};

export type VariantRow = {
    id: string;
    sku: string;
    barcode: string | null;
    /** The combination in attribute order, e.g. "M / Navy". */
    label: string;
    values: { attribute: string; value: string }[];
    /** The override only; null means the product's figure applies. */
    wholesale_price_override: Money | null;
    base_cost_override: Money | null;
    /** The price that applies, override or not, rendered by the server. */
    wholesale_price: Money;
    overrides_price: boolean;
    is_active: boolean;
} & ProductLogisticsFields;

/**
 * Quantity pricing for one scope: the whole product (variant_id null) or one
 * variation. `applies` is false for a band the server no longer charges, because
 * the base price was cut below it.
 */
export type PriceTierScope = {
    variant_id: string | null;
    label: string | null;
    base_price: Money;
    tiers: {
        min_quantity: number;
        unit_price: Money;
        applies: boolean;
    }[];
};

/** What a partner website would render, built on the server (§34.3). */
export type SeoPreview = {
    metadata: {
        title: string;
        description: string | null;
        keywords: string | null;
        image: { url: string; alt: string | null } | null;
    };
    /** Pretty-printed JSON-LD. */
    schema: string;
};

export type RelatedProductRow = {
    id: string;
    name: string;
    sku: string;
    status: string;
    status_tone?: StatusTone;
};

export type MerchandisingState = {
    is_featured: boolean;
    featured_at: string | null;
    related: RelatedProductRow[];
};

/** Who may see a product: by package, and optionally by named account. */
export type ProductEligibilityState = {
    package_scope: 'all' | 'selected';
    package_ids: string[];
    account_scope: 'any' | 'selected';
    accounts: { id: string; name: string }[];
};

export type AccountMatch = { id: string; name: string; status: string };

export type MediaRow = {
    id: string;
    type: 'image' | 'video';
    url: string;
    alt_text: string | null;
    position: number;
    mime_type: string;
    size_bytes: number;
    width: number | null;
    height: number | null;
    variant_id: string | null;
    variant_label: string | null;
};

/** What the server will accept — never above PHP's own upload limit. */
export type MediaLimits = {
    image_types: string[];
    video_types: string[];
    /** Formatted by the server, e.g. "2" or "2.5". */
    image_max_mb: string;
    video_max_mb: string;
    max_items: number;
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
    publish?: boolean;
    enable_channels?: boolean;
    disable_channels?: boolean;
};

export type SalesChannelName = 'dropshipping' | 'wholesale';

/** One sales channel's state on a product, for the administrator. */
export type ProductChannelState = {
    channel: SalesChannelName;
    status: string;
    tone: StatusTone;
    enabled: boolean;
};

/**
 * A product as a business account browses it. Only one channel's prices are
 * ever present, and never the base cost.
 */
export type BrowseCard = {
    slug: string;
    name: string;
    sku: string;
    category: string;
    brand: string | null;
    is_featured: boolean;
    image: { url: string; alt: string | null } | null;
    wholesale_price?: Money;
    min_order_quantity?: number;
    has_quantity_pricing?: boolean;
    /** Wholesale only: stock this account can order, as a yes or no (P4-1). */
    in_stock?: boolean;
    suggested_selling_price?: Money | null;
    minimum_selling_price?: Money | null;
    maximum_selling_price?: Money | null;
};

export type BrowseDetail = {
    slug: string;
    name: string;
    sku: string;
    short_description: string | null;
    description: string | null;
    category: string;
    brand: string | null;
    media: { type: 'image' | 'video'; url: string; alt: string | null }[];
    variants: {
        label: string;
        sku: string;
        wholesale_price?: Money;
        /** Wholesale only: what this account can order of this variation (P4-2). */
        in_stock?: boolean;
        available?: number;
        quantity_pricing?: { min_quantity: number; unit_price: Money }[];
    }[];
    wholesale_price?: Money;
    min_order_quantity?: number;
    max_order_quantity?: number | null;
    quantity_pricing?: { min_quantity: number; unit_price: Money }[];
    /** Wholesale only: any unit orderable now; `available` is null when variations carry their own. */
    in_stock?: boolean;
    available?: number | null;
    suggested_selling_price?: Money | null;
    minimum_selling_price?: Money | null;
    maximum_selling_price?: Money | null;
};
