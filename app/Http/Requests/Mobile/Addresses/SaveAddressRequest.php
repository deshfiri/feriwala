<?php

namespace App\Http\Requests\Mobile\Addresses;

use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Rules\ValidBdLocationHierarchy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or replacing an address. Replacing sends the whole address again,
 * exactly as the web form does.
 */
class SaveAddressRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** One of `types` from the address list. */
            'type' => ['required', Rule::in(AddressOwnerType::BusinessAccount->allowedAddressTypes())],
            /** Who to ask for at this address. */
            'contact_name' => ['required', 'string', 'max:150'],
            /**
             * International format with the country code.
             *
             * @example "+8801711111111"
             */
            'contact_mobile' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            /** `id` of a division from the locations lookup. */
            'division_id' => ['required', 'integer', new ValidBdLocationHierarchy(BdLocationType::Division)],
            /** `id` of a district inside that division. */
            'district_id' => ['required', 'integer', new ValidBdLocationHierarchy(BdLocationType::District)],
            /** `id` of an upazila inside that district. */
            'upazila_id' => ['required', 'integer', new ValidBdLocationHierarchy(BdLocationType::Upazila)],
            /** `id` of a union inside that upazila, when there is one. */
            'union_id' => ['nullable', 'integer', new ValidBdLocationHierarchy(BdLocationType::Union)],
            /** House, road and area. */
            'detailed_address' => ['required', 'string', 'max:500'],
            'landmark' => ['nullable', 'string', 'max:150'],
            'postcode' => ['nullable', 'string', 'max:16'],
            /** Make this the default address. */
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The validated input with every optional field present, ready for the save action.
     *
     * @return array{type: string, contact_name: string, contact_mobile: string, division_id: int, district_id: int, upazila_id: int, union_id: int|null, detailed_address: string, landmark: string|null, postcode: string|null, is_default: bool}
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            ...$validated,
            'union_id' => $validated['union_id'] ?? null,
            'landmark' => $validated['landmark'] ?? null,
            'postcode' => $validated['postcode'] ?? null,
            'is_default' => (bool) ($validated['is_default'] ?? false),
        ];
    }
}
