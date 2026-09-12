<?php

namespace App\Integrations\Payment\Exceptions;

use App\Integrations\Payment\Data\GatewayCapability;
use RuntimeException;

/**
 * Something asked a provider for an operation it does not offer (§26.4).
 *
 * Distinct from a gateway being unreachable or misconfigured: this is not a
 * fault anybody can fix by trying again or entering a credential. The provider
 * has no such endpoint, and the honest answer is that the action is not
 * available here rather than a request that fails in an interesting way.
 *
 * Reaching this exception means a screen offered a button it should not have, so
 * it is deliberately loud.
 */
class GatewayCapabilityMissing extends RuntimeException
{
    public static function for(string $gateway, GatewayCapability $capability): self
    {
        return new self(sprintf(
            '%s does not support %s here.',
            $gateway,
            mb_strtolower($capability->label()),
        ));
    }
}
