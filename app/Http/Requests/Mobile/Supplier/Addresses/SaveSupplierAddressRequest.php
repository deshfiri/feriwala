<?php

namespace App\Http\Requests\Mobile\Supplier\Addresses;

use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Rules\ValidBdLocationHierarchy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSupplierAddressRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** One of the `types` on `GET /supplier/addresses`: `registered`, `pickup` or `return`. */
            'type' => ['required', Rule::in(AddressOwnerType::Supplier->allowedAddressTypes())],
            /** Who to ask for at this address. */
            'contact_name' => ['required', 'string', 'max:150'],
            /**
             * Contact mobile in international form.
             *
             * @example +8801712345678
             */
            'contact_mobile' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            /** `id` of a division from `GET /supplier/locations/divisions`. */
            'division_id' => ['required', 'integer', new ValidBdLocationHierarchy(BdLocationType::Division)],
            /** `id` of a district inside that division. */
            'district_id' => ['required', 'integer', new ValidBdLocationHierarchy(BdLocationType::District)],
            /** `id` of an upazila inside that district. */
            'upazila_id' => ['required', 'integer', new ValidBdLocationHierarchy(BdLocationType::Upazila)],
            /** `id` of a union inside that upazila, when there is one. */
            'union_id' => ['nullable', 'integer', new ValidBdLocationHierarchy(BdLocationType::Union)],
            /** Street, house and road. */
            'detailed_address' => ['required', 'string', 'max:500'],
            /** A nearby landmark. */
            'landmark' => ['nullable', 'string', 'max:150'],
            /** Postcode. */
            'postcode' => ['nullable', 'string', 'max:16'],
            /** Make this the default address of its type. */
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}