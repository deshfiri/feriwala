<?php

namespace App\Domain\Sourcing\Exceptions;

use DomainException;

/**
 * A sourcing-group change that would break a rule, with the reason in plain
 * words for the person making it.
 */
class SourcingGroupRefused extends DomainException
{
    public static function inactiveGroup(): self
    {
        return new self('This sourcing group is inactive, so nothing can be added to it.');
    }

    public static function alreadyInAGroup(string $productName, string $groupName): self
    {
        return new self("{$productName} is already in the sourcing group \"{$groupName}\". Remove it there first.");
    }

    public static function notAMember(): self
    {
        return new self('That product is not an active member of this sourcing group.');
    }

    public static function canonicalHasMembers(): self
    {
        return new self('The canonical product cannot be removed while other products depend on it. Make another product canonical first.');
    }

    public static function variantNotOfProduct(): self
    {
        return new self('That variation does not belong to the selected product.');
    }

    public static function variantRequired(string $productName): self
    {
        return new self("{$productName} has variations, so each one must be mapped to a canonical variation individually.");
    }

    public static function alreadyMapped(): self
    {
        return new self('This variation already has an active mapping. Remove it before mapping it somewhere else.');
    }

    public static function inUseByOpenAllocation(): self
    {
        return new self('An open order allocation relies on this mapping. Release or complete it first.');
    }

    public static function reasonRequired(): self
    {
        return new self('A reason is required and is recorded against this change.');
    }
}
