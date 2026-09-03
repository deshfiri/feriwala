<?php

namespace App\Integrations\Payment\Data;

/**
 * Where to send the user to pay.
 */
class GatewayRedirect
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $gatewayReference = null,
        /** @var array<string, string> */
        public readonly array $formFields = [],
    ) {}

    /**
     * Whether the gateway wants a POST rather than a plain redirect.
     */
    public function requiresForm(): bool
    {
        return $this->formFields !== [];
    }
}
