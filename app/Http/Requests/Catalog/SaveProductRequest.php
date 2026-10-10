<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\CentralProductFields;
use App\Domain\Catalog\Enums\ItemCondition;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Sourcing\Models\ProductSourcingGroup;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Money;
use App\Support\Money\Rules\DecimalAmountRule;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveProductRequest extends FormRequest
{
    /**
     * The protected central fields this form owns (§12).
     *
     * @var array<int, string>
     */
    public const OWNED = ['sku', 'barcode', 'wholesale_price', 'base_cost'];

    /**
     * Refused before a single rule runs (§12).
     *
     * A person who may not write the catalogue gets a 403, not a list of which
     * of their fields would have been invalid — the second is an answer, and
     * they were not entitled to one. The controller asks the policy again.
     */
    public function authorize(): bool
    {
        return CatalogPolicy::canWrite($this->user(), $this->isMethod('post'));
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
             * The central SKU (§12), the BPC: exactly nine letters or digits,
             * a code rather than a slug. Left blank,
             * {@see \App\Domain\Catalog\Actions\ManageProducts} invents one
             * rather than demanding it. A product that already carries an
             * older-format BPC keeps it: the format is only asked of a BPC
             * being set or changed.
             */
            'sku' => [
                'nullable', 'string', 'max:64',
                function (string $attribute, mixed $value, Closure $fail) use ($existing): void {
                    if ($existing !== null && $existing->sku === $value) {
                        return;
                    }

                    if (preg_match('/^[A-Z0-9]{9}$/', (string) $value) !== 1) {
                        $fail(__('catalog.products.bpc_format'));
                    }
                },
                Rule::unique(Product::class, 'sku')->ignore($existing?->id),

                // One namespace with variants; the database trigger holds it too.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (ProductVariant::query()->where('sku', $value)->exists()) {
                        $fail(__('catalog.variants.sku_used_by_variant'));
                    }
                },
            ],

            /*
             * Always a 13-digit EAN-13 code. Left blank, ManageProducts
             * generates one with a valid check digit, so a downloaded
             * barcode always actually scans.
             */
            'barcode' => [
                'nullable', 'digits:13',
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
             * Entered in Taka, like every other human-facing amount (D4,
             * §36.1). `DecimalAmountRule` refuses excess precision outright
             * rather than rounding it, because a silently rounded price is a
             * wrong price somebody did not notice. Turned into an exact
             * {@see \App\Support\Money\Money} in `productAttributes()` below.
             */
            'base_cost' => ['required', new DecimalAmountRule],
            'wholesale_price' => ['required', new DecimalAmountRule],

            /*
             * What partner websites put in the page head (§34.3), bounded to
             * what a search engine will actually show. Blank falls back to the
             * product's own name and description.
             */
            // Optional, on create only: put the new product straight into a
            // sourcing group. Handled by the controller, never stored on the product.
            'sourcing_group_id' => ['nullable', 'string', Rule::exists(ProductSourcingGroup::class, 'public_id')],

            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:200'],
            'meta_keywords' => ['nullable', 'string', 'max:255'],

            // Product schema fields only the catalogue can know.
            'mpn' => ['nullable', 'string', 'max:70', 'regex:/^[A-Za-z0-9._\-\/ ]+$/'],
            'item_condition' => ['nullable', Rule::enum(ItemCondition::class)],

            /*
             * One of this product's own images, by public id. Another product's
             * image is refused: a sharing card showing the wrong product is
             * worse than one showing none.
             */
            'social_image_id' => [
                'nullable', 'string',
                function (string $attribute, mixed $value, Closure $fail) use ($existing): void {
                    $belongs = $existing !== null && ProductMedia::query()
                        ->where('product_id', $existing->id)
                        ->where('public_id', $value)
                        ->where('type', ProductMedia::TYPE_IMAGE)
                        ->exists();

                    if (! $belongs) {
                        $fail(__('catalog.seo.image_not_this_product'));
                    }
                },
            ],

            // Order quantities (§14): a blank minimum is one, a blank maximum no limit.
            'min_order_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_order_quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],

            // Selling-price guidance for partners (§15.1). Blank is no bound.
            'suggested_selling_price' => ['nullable', new DecimalAmountRule],
            'minimum_selling_price' => ['nullable', new DecimalAmountRule],
            'maximum_selling_price' => ['nullable', new DecimalAmountRule],

            /*
             * Physical logistics and packaging (beta-critical batch, Commit
             * 1). Weight in whole grams, every dimension in centimetres --
             * the one canonical unit each measurement is stored in, so
             * nothing downstream has to guess. `min:1`/`min:0.01` rather than
             * `min:0` rejects a mistyped zero outright, since a product with
             * a genuine zero weight does not exist -- "unknown" is `null`,
             * never zero.
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

            /*
             * The SKU and both figures are this form's to set; the lifecycle,
             * channels, eligibility, quantity pricing, stock and the identifiers
             * the system assigns are not, and are refused rather than ignored
             * (§12).
             */
            ...CentralProductFields::rules(self::OWNED, $this->all()),
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

                // Money fields compare on the parsed exact decimal, not a raw
                // (int) cast of the Taka string a person typed — "2490.50"
                // must compare as 2490.50, not truncate to 2490. Skipped when
                // the field already failed DecimalAmountRule above.
                $decimal = function (string $key) use ($validator, $data): ?string {
                    if ($validator->errors()->has($key)) {
                        return null;
                    }

                    return DecimalAmount::parseOrNull($data[$key] ?? null)?->toDecimal();
                };

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

                $suggested = $decimal('suggested_selling_price');
                $floor = $decimal('minimum_selling_price');
                $ceiling = $decimal('maximum_selling_price');

                if ($floor !== null && $ceiling !== null && bccomp($floor, $ceiling, 2) > 0) {
                    $validator->errors()->add('minimum_selling_price', __('catalog.products.bounds.selling_range'));
                }

                if ($suggested !== null && (($floor !== null && bccomp($suggested, $floor, 2) < 0) || ($ceiling !== null && bccomp($suggested, $ceiling, 2) > 0))) {
                    $validator->errors()->add('suggested_selling_price', __('catalog.products.bounds.suggested_outside'));
                }
            },
        ];
    }

    /**
     * The validated data, with the Taka strings normalised to the exact
     * decimals ManageProducts turns into {@see Money}.
     *
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        $validated = Arr::except($this->validated(), ['sourcing_group_id']);

        foreach (['base_cost', 'wholesale_price'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = DecimalAmount::parse($validated[$field])->toDecimal();
            }
        }

        foreach (['suggested_selling_price', 'minimum_selling_price', 'maximum_selling_price'] as $field) {
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
            'sku' => 'BPC',
            'category_id' => 'category',
            'brand_id' => 'brand',
            'base_cost' => 'base cost',
            'wholesale_price' => 'wholesale price',
            'min_order_quantity' => 'minimum order quantity',
            'max_order_quantity' => 'maximum order quantity',
            'suggested_selling_price' => 'suggested selling price',
            'minimum_selling_price' => 'minimum selling price',
            'maximum_selling_price' => 'maximum selling price',
            'net_weight_grams' => 'net weight',
            'shipping_weight_grams' => 'shipping weight',
            'length_cm' => 'length',
            'width_cm' => 'width',
            'height_cm' => 'height',
            'pieces_per_box' => 'pieces per box',
            'box_weight_grams' => 'box weight',
            'box_length_cm' => 'box length',
            'box_width_cm' => 'box width',
            'box_height_cm' => 'box height',
        ];
    }
}
