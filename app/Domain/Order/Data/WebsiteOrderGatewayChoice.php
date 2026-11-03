<?php

namespace App\Domain\Order\Data;

use App\Domain\Billing\Enums\GatewayOwnership;
use App\Domain\Billing\Enums\PartnerGatewayFallbackReason;

/**
 * Which gateway a website order's payment is taken through, decided once at
 * placement and never revisited (D36).
 *
 * For a Partner-owned payment `versionId` is the exact gateway configuration
 * version it will be verified and reconciled with. When a Storefront has a Partner
 * gateway it could not use, `fallbackReason` says why, as a name from a fixed
 * list.
 */
readonly class WebsiteOrderGatewayChoice
{
    public function __construct(
        public string $gateway,
        public string $mode,
        public GatewayOwnership $ownership,
        public ?int $versionId = null,
        public ?PartnerGatewayFallbackReason $fallbackReason = null,
        public ?string $partnerProvider = null,
    ) {}

    public function isPartnerOwned(): bool
    {
        return $this->ownership === GatewayOwnership::Partner;
    }
}
