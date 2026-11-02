<?php

namespace App\Http\Resources\Mobile\Addresses;

use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Location\Models\BdLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One address in the account's address book.
 *
 * @mixin SharedAddress
 */
class AddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            /** One of `types` from the address list. */
            'type' => $this->type,
            'is_active' => $this->status === AddressStatus::Active,
            'is_default' => $this->is_default,
            'contact_name' => $this->contact_name,
            'contact_mobile' => $this->contact_mobile,
            'detailed_address' => $this->detailed_address,
            'landmark' => $this->landmark,
            'postcode' => $this->postcode,
            /** The place names as they were when the address was saved. */
            'location_snapshot' => $this->location_snapshot,
            /** The chosen places, with the `id` values the form submits back. */
            'location' => [
                'division' => $this->locationOption($this->division),
                'district' => $this->locationOption($this->district),
                'upazila' => $this->locationOption($this->upazila),
                'union' => $this->locationOption($this->union),
            ],
        ];
    }

    /**
     * @return array{id: int, source_id: string, name_en: string, name_bn: string}|null
     */
    protected function locationOption(?BdLocation $location): ?array
    {
        return $location === null ? null : [
            'id' => $location->id,
            'source_id' => $location->source_id,
            'name_en' => $location->name_en,
            'name_bn' => $location->name_bn,
        ];
    }
}
