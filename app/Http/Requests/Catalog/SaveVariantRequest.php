<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVariantRequest extends FormRequest
{
    /**
     * Authorisation is the controller's, through the policy — one lookup, one
     * place.
     */
    public function authorize(): bool
    {
        return true;
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

            // Blank means the product's own figure applies.
            'wholesale_price_minor' => ['nullable', 'integer', 'min:0', 'max:'.SaveProductRequest::MAX_MINOR],
            'base_cost_minor' => ['nullable', 'integer', 'min:0', 'max:'.SaveProductRequest::MAX_MINOR],

            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sku' => 'SKU',
            'values' => 'combination',
            'wholesale_price_minor' => 'wholesale price',
            'base_cost_minor' => 'base cost',
        ];
    }
}
