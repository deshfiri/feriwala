<?php

namespace App\Integrations\Courier\Steadfast;

/**
 * The answer to a status lookup: whether Steadfast knows the parcel, and its
 * raw `delivery_status` when it does.
 */
final class SteadfastStatusLookup
{
    public function __construct(
        public readonly bool $found,
        public readonly ?string $status,
    ) {}
}
