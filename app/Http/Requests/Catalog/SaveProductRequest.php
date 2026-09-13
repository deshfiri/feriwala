<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    /**
     * The largest figure a form may post, in minor units.
     *
     * Far above any real price (ten billion taka) and far below BIGINT, so an
     * absurd entry is refused as a validation message rather than surfacing as a
     * database overflow.
     */
    public const MAX_MINOR = 1_000_000_000_000;

    /**
     * Authorisation is the controller's, through the policy — one lookup, one
     * place.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalised before the rules run, so the uniqueness check compares what
     * will actually be stored.
     */
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
        $product = $this->route('product');
        $existing = is_string($product)
            ? Product::query()->where('public_id', $product)->first()
            : null;

        return [
            'name' => ['required', 'string', 'max:160'],

            'slug' => [
                'nullable', 'string', 'max:180', 'regex:/^[a-z0-9-]+$/',
                Rule::unique(Product::class, 'slug')->ignore($existing?->id),
            ],

            /*
             * The central SKU (§12). Letters, digits, dots, dashes and
             * underscores, starting with a letter or digit — the characters a
             * label printer, a barcode scanner and a spreadsheet all agree on.
             */
            'sku' => [
                'required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/',
                Rule::unique(Product::class, 'sku')->ignore($existing?->id),

                // One namespace with variants; the database trigger holds it too.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (ProductVariant::query()->where('sku', $value)->exists()) {
                        $fail(__('catalog.variants.sku_used_by_variant'));
                    }
                },
            ],

            'barcode' => [
                'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique(Product::class, 'barcode')->ignore($existing?->id),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (ProductVariant::query()->where('barcode', $value)->exists()) {
                        $fail(__('catalog.variants.barcode_used_by_variant'));
                    }
                },
            ],

            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],

            // Named by public id, never a row id a form could walk (§34.2).
            'category_id' => ['required', 'string', Rule::exists(Category::class, 'public_id')],
            'brand_id' => ['nullable', 'string', Rule::exists(Brand::class, 'public_id')],

            /*
             * Integer minor units, like every other amount this application
             * accepts (D4, §36.1). `integer` refuses "2490.50" outright rather
             * than rounding it, because a silently rounded price is a wrong
             * price somebody did not notice.
             */
            'base_cost_minor' => ['required', 'integer', 'min:0', 'max:'.self::MAX_MINOR],
            'wholesale_price_minor' => ['required', 'integer', 'min:0', 'max:'.self::MAX_MINOR],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sku' => 'SKU',
            'category_id' => 'category',
            'brand_id' => 'brand',
            'base_cost_minor' => 'base cost',
            'wholesale_price_minor' => 'wholesale price',
        ];
    }
}
