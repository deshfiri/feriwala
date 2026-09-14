<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Actions\BulkUpdateProducts;
use App\Domain\Catalog\CentralProductFields;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Policies\CatalogPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkProductRequest extends FormRequest
{
    public const TRANSITION = 'transition';

    public const CHANNEL = 'channel';

    public const FEATURE = 'feature';

    public const CATEGORY = 'category';

    public const BRAND = 'brand';

    /**
     * Refused before a rule runs for anybody holding none of the permissions a
     * bulk action uses (§12). The controller then asks for the one the chosen
     * action needs, and the action asks again per product.
     */
    public function authorize(): bool
    {
        return CatalogPolicy::canActInBulk($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Named by public id (§34.2); unknown ids are reported, not an error.
            'products' => ['required', 'array', 'min:1', 'max:'.BulkUpdateProducts::MAX_PRODUCTS],
            'products.*' => ['required', 'string', 'max:40', 'distinct'],

            'action' => ['required', 'string', Rule::in([self::TRANSITION, self::CHANNEL, self::FEATURE, self::CATEGORY, self::BRAND])],

            // Assigning products (§11.3), by public id. A null brand removes it.
            'category' => ['required_if:action,'.self::CATEGORY, 'nullable', 'string', Rule::exists(Category::class, 'public_id')],
            'brand' => ['present_if:action,'.self::BRAND, 'nullable', 'string', Rule::exists(Brand::class, 'public_id')],

            'status' => [
                'required_if:action,'.self::TRANSITION, 'nullable', 'string',
                Rule::in(array_map(fn (ProductStatus $status) => $status->value, ProductStatus::lifecycle())),
            ],

            // Retiring asks for a reason, in bulk as on one product.
            'reason' => [
                Rule::requiredIf(fn () => $this->input('action') === self::TRANSITION
                    && is_string($this->input('status'))
                    && ProductStatus::tryFrom($this->input('status'))?->requiresReason() === true),
                'nullable', 'string', 'max:2000',
            ],

            'channel' => ['required_if:action,'.self::CHANNEL, 'nullable', 'string', Rule::enum(SalesChannel::class)],

            'enable' => ['required_if:action,'.self::CHANNEL.','.self::FEATURE, 'boolean'],

            // A lifecycle move is the only central field a bulk action sets, and
            // its list names products that already exist (§12).
            ...CentralProductFields::rules(['status', 'products'], $this->all()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CentralProductFields::messages($this->all());
    }
}
