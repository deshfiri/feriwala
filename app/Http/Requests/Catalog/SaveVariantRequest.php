<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\CentralProductFields;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Money;
use App\Support\Money\Rules\DecimalAmountRule;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVariantRequest extends FormRequest
{
    /**
     * Refused before a single rule runs (§12): an unauthorised writer learns
     * nothing about which fields would have passed. The controller asks again.
     */
    public function authorize(): bool
    {
        return CatalogPolicy::canWrite($this->user(), $this->isMethod('post'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => mb_strtoupper(trim($this->input('sku')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $variant = $this->route('variant');
        $existing = is_string($variant)
            ? ProductVariant::query()->where('public_id', $variant)->first()
            : null;

        $creating = $existing === null;

        return [
            /*
             * Unique across variants, and never a product's SKU either: one
             * namespace, so a scanner finds exactly one thing. The database
             * trigger holds this too; this is the field-level message.
             */
            'sku' => [
                'required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique(ProductVariant::class, 'sku')->ignore($existing?->id),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (Product::query()->where('sku', $value)->exists()) {
                        $fail(__('catalog.variants.sku_used_by_product'));
                    }
                },
            ],

            'barcode' => [
                'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique(ProductVariant::class, 'barcode')->ignore($existing?->id),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (Product::query()->where('barcode', $value)->exists()) {
                        $fail(__('catalog.variants.barcode_used_by_product'));
                    }
                },
            ],

            /*
             * The combination, chosen once. On an edit it is not accepted at
             * all rather than silently ignored — a different combination is a
             * different variant.
             */
            'values' => $creating
                ? ['required', 'array', 'min:1']
                : ['prohibited'],
            'values.*' => ['string', 'distinct', Rule::exists(ProductAttributeValue::class, 'public_id')],

            // Blank means the product's own figure applies. Entered in Taka
            // and normalised to an exact decimal in variantAttributes() below.
            'wholesale_price' => ['nullable', new DecimalAmountRule],
            'base_cost' => ['nullable', new DecimalAmountRule],

            'is_active' => ['boolean'],

            /*
             * This variant's own logistics override (beta-critical batch,
             * Commit 1). Blank means the product's own figure applies --
             * see App\Domain\Catalog\Data\ProductLogistics::forVariant().
             */
            'net_weight_grams' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'shipping_weight_grams' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'length_cm' => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            'width_cm' => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            'height_cm' => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            'ships_by_box' => ['nullable', 'boolean'],
            'pieces_per_box' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'box_weight_grams' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'box_length_cm' => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            'box_width_cm' => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            'box_height_cm' => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            'is_fragile' => ['nullable', 'boolean'],

            // Its own SKU and figures only; never its product, combination key or stock (§12).
            ...CentralProductFields::rules(SaveProductRequest::OWNED, $this->all()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CentralProductFields::messages($this->all());
    }

    /**
     * The validated data, with the Taka strings normalised to the exact
     * decimals ManageVariants turns into {@see Money}.
     *
     * @return array<string, mixed>
     */
    public function variantAttributes(): array
    {
        $validated = $this->validated();

        foreach (['wholesale_price', 'base_cost'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = DecimalAmount::parseOrNull($validated[$field])?->toDecimal();
            }
        }

        return $validated;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sku' => 'SKU',
            'values' => 'combination',
            'wholesale_price' => 'wholesale price',
            'base_cost' => 'base cost',
        ];
    }
}
