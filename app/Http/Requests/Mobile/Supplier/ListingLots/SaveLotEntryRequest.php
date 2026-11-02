<?php

namespace App\Http\Requests\Mobile\Supplier\ListingLots;

use App\Domain\Supplier\Models\SupplierProductListingItem;
use App\Http\Requests\Mobile\Supplier\Listings\SaveListingRequest;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Validation\Rule;

/**
 * One product entry in a listing lot: the product and its variations.
 *
 * The same shape as a single listing, with the lot workspace's extras (supply
 * mode, capacity, attribute values). Images are not sent here: they go through
 * the listing media endpoints, using this entry's `id`.
 */
class SaveLotEntryRequest extends SaveListingRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** Product name. */
            'product_name' => ['required', 'string', 'max:255'],
            /** Free-text description. */
            'description' => ['nullable', 'string', 'max:5000'],
            /** `value` of a category from `GET listings/options`. */
            'category_id' => ['nullable', 'string'],
            /** A category to suggest when none fits. */
            'category_suggestion' => ['nullable', 'string', 'max:255'],
            /** `value` of a brand from `GET listings/options`. */
            'brand_id' => ['nullable', 'string'],
            /** A brand to suggest when none fits. */
            'brand_suggestion' => ['nullable', 'string', 'max:255'],
            /** A note for the reviewer. */
            'supplier_note' => ['nullable', 'string', 'max:2000'],

            /** The proposed variations. At least one. Sending an existing `id` edits it; omitting a variation drops it. */
            'items' => ['required', 'array', 'min:1'],
            /** `id` of an existing variation to edit; omit to add one. */
            'items.*.id' => ['nullable', 'string'],
            'items.*.variant_label' => ['nullable', 'string', 'max:255'],
            'items.*.supplier_sku' => ['required', 'string', 'max:100'],
            /**
             * What the Supplier wants to be paid per unit, in flat Taka as a decimal string with at most two decimals.
             *
             * @example "1000.00"
             */
            'items.*.supplier_rate' => ['required', new DecimalAmountRule],
            /** ISO currency code. Defaults to `BDT`. */
            'items.*.currency_code' => ['nullable', 'string', 'size:3'],

            /** How this variation is supplied. Defaults to `ready_stock`. */
            'items.*.supply_mode' => ['nullable', Rule::in(['ready_stock', 'on_demand', 'pre_order'])],
            /** Stock on hand. Leave out when not declared: that is not the same as zero. */
            'items.*.available_quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.minimum_supply_quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.lead_time_days' => ['nullable', 'integer', 'min:0'],
            'items.*.expected_availability_at' => ['nullable', 'date'],
            'items.*.fulfilment_capacity' => ['nullable', 'integer', 'min:0'],
            'items.*.warranty' => ['nullable', 'string', 'max:255'],
            'items.*.return_conditions' => ['nullable', 'string', 'max:1000'],

            /** `value` of catalogue attribute values from `GET listings/options`. A value already in the catalogue only. */
            'items.*.attribute_value_ids' => ['nullable', 'array'],
            'items.*.attribute_value_ids.*' => ['string'],

            ...SupplierProductListingItem::proposedLogisticsValidationRules(),
        ];
    }
}
