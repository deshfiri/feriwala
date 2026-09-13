<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

            // Order quantities (§14): a blank minimum is one, a blank maximum no limit.
            'min_order_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_order_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],

            // Selling-price guidance for partners (§15.1). Blank is no bound.
            'suggested_selling_price_minor' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_MINOR],
            'minimum_selling_price_minor' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_MINOR],
            'maximum_selling_price_minor' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_MINOR],
        ];
    }

    /**
     * The rules that compare one field with another.
     *
     * Written out rather than left to `gte`/`lte`, whose behaviour when the other
     * field is blank is not what "blank means no bound" needs. Each message is
     * put on the field an administrator would change.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $data = $validator->getData();
                $number = fn (string $key): ?int => isset($data[$key]) && is_numeric($data[$key]) ? (int) $data[$key] : null;

                $min = $number('min_order_quantity') ?? 1;
                $max = $number('max_order_quantity');

                if ($max !== null && $max < $min) {
                    $validator->errors()->add('max_order_quantity', __('catalog.products.bounds.max_below_min'));
                }

                /*
                 * A quantity tier starting above the maximum order quantity is a
                 * price nobody can ever be charged — refused here, on the
                 * field that would strand it.
                 */
                $product = $this->route('product');

                if ($max !== null && is_string($product)) {
                    $highestTier = ProductPriceTier::query()
                        ->whereHas('product', fn ($query) => $query->where('public_id', $product))
                        ->max('min_quantity');

                    if ($highestTier !== null && (int) $highestTier > $max) {
                        $validator->errors()->add('max_order_quantity', __('catalog.products.bounds.tier_above_max', [
                            'quantity' => $highestTier,
                        ]));
                    }
                }

                $suggested = $number('suggested_selling_price_minor');
                $floor = $number('minimum_selling_price_minor');
                $ceiling = $number('maximum_selling_price_minor');

                if ($floor !== null && $ceiling !== null && $floor > $ceiling) {
                    $validator->errors()->add('minimum_selling_price_minor', __('catalog.products.bounds.selling_range'));
                }

                if ($suggested !== null && (($floor !== null && $suggested < $floor) || ($ceiling !== null && $suggested > $ceiling))) {
                    $validator->errors()->add('suggested_selling_price_minor', __('catalog.products.bounds.suggested_outside'));
                }
            },
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
            'min_order_quantity' => 'minimum order quantity',
            'max_order_quantity' => 'maximum order quantity',
            'suggested_selling_price_minor' => 'suggested selling price',
            'minimum_selling_price_minor' => 'minimum selling price',
            'maximum_selling_price_minor' => 'maximum selling price',
        ];
    }
}
