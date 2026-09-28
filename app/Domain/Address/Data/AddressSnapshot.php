<?php

namespace App\Domain\Address\Data;

use App\Domain\Address\Models\SharedAddress;

/**
 * An address exactly as it stood at the moment something asked for it —
 * {@see SharedAddress::toSnapshot()}.
 *
 * Built but not yet attached anywhere: nothing in this application consumes a
 * `SharedAddress` today (existing order/checkout consumers use the separate
 * `UserAddress` model), so wiring this onto an Order, a shipment or any other
 * record is future work for whichever domain first needs it.
 */
readonly class AddressSnapshot
{
    /**
     * @param  array<string, mixed>  $locationSnapshot  bilingual division/district/upazila/union names, resolved at save time
     */
    public function __construct(
        public string $type,
        public string $contactName,
        public string $contactMobile,
        public string $detailedAddress,
        public ?string $landmark,
        public ?string $postcode,
        public array $locationSnapshot,
    ) {}
}
