<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Actions\BulkUpdateProducts;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Policies\CatalogPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkProductRequest extends FormRequest
{
    public const TRANSITION = 'transition';

    public const CHANNEL = 'channel';

    public const FEATURE = 'feature';

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

            'action' => ['required', 'string', Rule::in([self::TRANSITION, self::CHANNEL, self::FEATURE])],

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
        ];
    }
}
