<?php

namespace App\Domain\Sourcing\Exceptions;

use DomainException;

/**
 * A Same Product change that would break a rule, in plain words for the person
 * making it.
 */
class ProductLinkRefused extends DomainException
{
    public static function selfLink(): self
    {
        return new self('A Product cannot be linked to itself.');
    }

    public static function alreadyLinked(string $first, string $second): self
    {
        return new self("{$first} and {$second} are already linked as the same Product.");
    }

    public static function notLinked(): self
    {
        return new self('That link is not active.');
    }

    public static function variantNotOfProduct(): self
    {
        return new self('That variation does not belong to the Product it was chosen for.');
    }

    public static function variantRequired(string $productName): self
    {
        return new self("{$productName} has variations, so choose which variation matches.");
    }

    public static function variantsNotAllowed(string $productName): self
    {
        return new self("{$productName} has no variations, so there is nothing to choose.");
    }

    public static function alreadyMapped(): self
    {
        return new self('Those two variations are already matched.');
    }

    public static function notMapped(): self
    {
        return new self('That variation match is not active.');
    }
}
