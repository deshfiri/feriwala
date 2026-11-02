<?php

namespace App\Http\Requests\Mobile\Supplier\Listings;

use App\Domain\Supplier\Models\SupplierProductListingItem;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A Product Listing Request: the product and its proposed variations.
 *
 * Money arrives as a decimal Taka string and is parsed at this boundary, never
 * as a number. A Supplier only ever proposes its own `supplier_rate`; the
 * platform's rates are staff's and are not accepted here.
 */
class SaveListingRequest extends FormRequest
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

            /** The proposed variations. At least one. Sending an existing `id` edits it; omitting a variation drops it while the listing is a draft or sent back. */
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
            'items.*.available_quantity' => ['required', 'integer', 'min:0'],
            'items.*.minimum_supply_quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.lead_time_days' => ['nullable', 'integer', 'min:0'],
            'items.*.warranty' => ['nullable', 'string', 'max:255'],
            'items.*.return_conditions' => ['nullable', 'string', 'max:1000'],

            ...SupplierProductListingItem::proposedLogisticsValidationRules(),
        ];
    }

    /**
     * The listing's own fields and its variations, with each rate parsed to Money.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    public function draft(): array
    {
        $data = $this->validated();

        $items = array_values(array_map(function (array $item) {
            $currency = isset($item['currency_code'])
                ? Currency::from($item['currency_code'])
                : Currency::base();

            $item['supplier_rate'] = DecimalAmount::parse($item['supplier_rate'], $currency);

            return $item;
        }, $data['items']));

        unset($data['items']);

        return [$data, $items];
    }
}
