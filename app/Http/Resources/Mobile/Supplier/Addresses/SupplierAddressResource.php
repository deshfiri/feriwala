<?php

namespace App\Http\Resources\Mobile\Supplier\Addresses;

use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Location\Models\BdLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of a Supplier's own registered, pickup or return addresses.
 *
 * @mixin SharedAddress
 */
class SupplierAddressResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Opaque public identifier (ULID). */
            'id' => $this->public_id,
            /** `registered`, `pickup` or `return`. */
            'type' => $this->type,
            'is_active' => $this->status === AddressStatus::Active,
            'is_default' => $this->is_default,
            'contact_name' => $this->contact_name,
            'contact_mobile' => $this->contact_mobile,
            'detailed_address' => $this->detailed_address,
            'landmark' => $this->landmark,
            'postcode' => $this->postcode,
            /** The location names as they were when the address was saved. */
            'location_snapshot' => $this->location_snapshot,
            /** The current directory entries; `id` is what the save endpoint takes. */
            'location' => [
                'division' => $this->option($this->division),
                'district' => $this->option($this->district),
                'upazila' => $this->option($this->upazila),
                'union' => $this->option($this->union),
            ],
        ];
    }

    /**
     * @return array{id: int, source_id: string, name_en: string, name_bn: string}|null
     */
    protected function option(?BdLocation $location): ?array
    {
        return $location === null ? null : [
            'id' => $location->id,
            'source_id' => $location->source_id,
            'name_en' => $location->name_en,
            'name_bn' => $location->name_bn,
        ];
    }
}