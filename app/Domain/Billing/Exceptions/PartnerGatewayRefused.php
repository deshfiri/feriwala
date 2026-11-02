<?php

namespace App\Domain\Billing\Exceptions;

use RuntimeException;

/**
 * A Partner gateway change that was refused (D36).
 *
 * The messages name a provider or a credential **key**, never a value: this is
 * shown to a person and may be logged, and a secret has no business in either.
 */
class PartnerGatewayRefused extends RuntimeException
{
    public static function featureOff(): self
    {
        return new self('Partner payment gateways are not switched on.');
    }

    public static function providerNotAllowed(string $provider): self
    {
        return new self("{$provider} is not a gateway Partners may use for their own Storefront.");
    }

    public static function cannotVerify(string $provider): self
    {
        return new self("{$provider} cannot confirm a payment with its provider, so it cannot take one.");
    }

    public static function unknownMode(): self
    {
        return new self('A gateway is either in sandbox or live mode.');
    }

    /**
     * @param  list<string>  $keys
     */
    public static function incomplete(string $provider, array $keys): self
    {
        return new self(sprintf(
            '%s still needs %s.',
            $provider,
            implode(', ', array_map(fn (string $key) => str_replace('_', ' ', $key), $keys)),
        ));
    }

    public static function modeChangeNeedsCredentials(): self
    {
        return new self('Changing between sandbox and live needs every credential entered again.');
    }
}
