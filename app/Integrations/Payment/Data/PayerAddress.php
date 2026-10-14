<?php

namespace App\Integrations\Payment\Data;

/**
 * The payer's postal address as a gateway that insists on one needs it.
 *
 * Always resolved from an address the payer's account already holds — never
 * defaulted or invented (see ResolvePayerAddress).
 */
readonly class PayerAddress
{
    public function __construct(
        public string $line,
        public string $city,
        public string $state,
        public string $postcode,
        public string $country,
    ) {}
}
