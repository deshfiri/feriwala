<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\PartnerGatewayFallbackReason;
use App\Domain\Billing\Models\PartnerGatewayConfigurationVersion;

/**
 * Whether a Storefront's own gateway can take a new payment right now (D36).
 *
 * Three answers, and they are different: no Partner gateway at all (nothing to
 * explain), a Partner gateway that cannot be used and why, or a version a new
 * payment may start with.
 */
readonly class PartnerGatewayCandidate
{
    public function __construct(
        public ?PartnerGatewayConfigurationVersion $version = null,
        public ?PartnerGatewayFallbackReason $reason = null,
        public ?string $provider = null,
    ) {}

    /** The Storefront has no active Partner gateway: the Platform gateway is simply the answer. */
    public static function none(): self
    {
        return new self;
    }

    public static function unusable(PartnerGatewayFallbackReason $reason, string $provider): self
    {
        return new self(reason: $reason, provider: $provider);
    }

    public static function usable(PartnerGatewayConfigurationVersion $version): self
    {
        return new self(version: $version, provider: $version->provider);
    }

    public function isUsable(): bool
    {
        return $this->version !== null;
    }

    public function hasPartnerGateway(): bool
    {
        return $this->provider !== null;
    }
}
