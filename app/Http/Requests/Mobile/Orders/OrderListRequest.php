<?php

namespace App\Http\Requests\Mobile\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtering the unified orders list and its export (D29).
 *
 * Deliberately lenient, as the web list is: a value that is not recognised is
 * ignored rather than refused, so a stale filter in the app never blocks the list.
 */
class OrderListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** Matches the number, the storefront's own number, the customer's name or phone. */
            'search' => ['nullable', 'string', 'max:120'],
            /** One of `website`, `wholesale`, `external`; anything else is ignored. */
            'source' => ['nullable', 'string', 'max:20'],
            /** A `store_key` from `options.stores`. */
            'store' => ['nullable', 'string', 'max:64'],
            /** A status `value` from `options.statuses`. */
            'status' => ['nullable', 'string', 'max:40'],
            /** A payment state `value` from `options.payments`. */
            'payment' => ['nullable', 'string', 'max:40'],
            /** First day to include, `YYYY-MM-DD`; anything else is ignored. */
            'from' => ['nullable', 'string', 'max:10'],
            /** Last day to include, `YYYY-MM-DD`; anything else is ignored. */
            'to' => ['nullable', 'string', 'max:10'],
            /** One of `placed_at`, `total`, `number`; defaults to `placed_at`. */
            'sort' => ['nullable', 'string', 'max:20'],
            /** `asc` or `desc`; defaults to `desc`. */
            'direction' => ['nullable', 'string', 'max:4'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
