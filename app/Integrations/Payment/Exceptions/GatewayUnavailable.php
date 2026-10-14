<?php

namespace App\Integrations\Payment\Exceptions;

use App\Integrations\Payment\Data\GatewayResult;
use RuntimeException;

/**
 * The gateway could not be reached or answered incomprehensibly.
 *
 * Distinct from a declined payment, which is a {@see GatewayResult}.
 * This one means the question could not be asked, so nothing may be concluded
 * about the payment — least of all that it failed.
 */
class GatewayUnavailable extends RuntimeException
{
    public static function forGateway(string $gateway, string $detail): self
    {
        return new self("The {$gateway} gateway could not be reached: {$detail}");
    }

    public static function malformedResponse(string $gateway): self
    {
        return new self(
            "The {$gateway} gateway returned a response that could not be understood. "
            .'The payment status is unknown and must be verified before anything is released.'
        );
    }

    /**
     * @param  list<string>  $missing  what the payer's best address lacks
     */
    public static function missingPayerAddress(string $gateway, array $missing = []): self
    {
        $what = $missing === []
            ? 'No address is on file for this account.'
            : 'Your address is missing: '.implode(', ', $missing).'.';

        return new self(
            "The {$gateway} gateway needs a complete postal address for the payer. {$what} "
            .'Add it under Addresses (street, city, division, postcode and country), then try again.'
        );
    }

    public static function missingCredentials(string $gateway): self
    {
        return new self(
            "No credentials are configured for the {$gateway} gateway. "
            .'Add them in settings — they are never read from code or committed config.'
        );
    }
}
