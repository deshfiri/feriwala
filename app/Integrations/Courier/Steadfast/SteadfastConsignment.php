<?php

namespace App\Integrations\Courier\Steadfast;

/**
 * A consignment Steadfast confirmed: its own identifier, the tracking code, and
 * the raw status it reported on creation.
 */
final class SteadfastConsignment
{
    public function __construct(
        public readonly string $consignmentId,
        public readonly ?string $trackingCode,
        public readonly ?string $status,
    ) {}
}
