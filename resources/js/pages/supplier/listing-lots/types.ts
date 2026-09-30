import type { Money } from '@/lib/money';
import type { StatusTone } from '@/lib/status';

export type AttributeOption = {
    id: string;
    name: string;
    values: { value: string; label: string }[];
};

export type LotEntryItem = {
    id: string;
    variant_label: string | null;
    supplier_sku: string;
    supplier_rate: Money;
    available_quantity: number | null;
    minimum_supply_quantity: number;
    lead_time_days: number | null;
    warranty: string | null;
    return_conditions: string | null;
    supply_mode: 'ready_stock' | 'on_demand' | 'pre_order';
    supply_mode_label: string;
    fulfilment_capacity: number | null;
    expected_availability_at: string | null;
    status: string;
    status_label: string;
    attribute_value_ids: string[];
    decision_note: string | null;
};

export type LotEntryMedia = {
    id: string;
    role: 'primary' | 'gallery';
    alt_text: string;
    download_url: string;
};

export type LotEntry = {
    id: string;
    product_name: string;
    description: string | null;
    category_id: string | null;
    category_suggestion: string | null;
    brand_id: string | null;
    brand_suggestion: string | null;
    supplier_note: string | null;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_editable: boolean;
    decision_note: string | null;
    primary_media_id: string | null;
    media: LotEntryMedia[];
    items: LotEntryItem[];
};

export type Lot = {
    id: string;
    reference: string;
    title: string | null;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_draft: boolean;
    submitted_at: string | null;
    entries: LotEntry[];
    status_history: {
        previous_status: string | null;
        new_status: string;
        reason: string | null;
        public_note: string | null;
        changed_at: string;
    }[];
};
