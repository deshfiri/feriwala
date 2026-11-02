<?php

namespace App\Http\Requests\Mobile\Wholesale;

use App\Domain\Order\Enums\IntendedResaleChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlaceOrderRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** The `fingerprint` of the confirmed checkout. Placing is idempotent per confirmation: a repeat returns the same order. */
            'fingerprint' => ['required', 'string', 'size:64'],
            /** A note for the order. */
            'customer_note' => ['nullable', 'string', 'max:1000'],
            /** Where the buyer means to resell. Reporting only; nothing is priced or refused by it. */
            'intended_resale_channel' => ['nullable', Rule::enum(IntendedResaleChannel::class)],
        ];
    }
}
