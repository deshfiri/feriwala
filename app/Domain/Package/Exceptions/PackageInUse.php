<?php

namespace App\Domain\Package\Exceptions;

use RuntimeException;

/**
 * A package cannot be retired while something still resolves through it (§8.1).
 *
 * Carries **what** references it, not just that something does. "This package
 * is in use" leaves an administrator hunting; "three KYC requirements name it,
 * and here they are" is an instruction they can act on.
 */
class PackageInUse extends RuntimeException
{
    /**
     * @param  array<int, string>  $references  human-readable, e.g. requirement names
     */
    public function __construct(
        string $message,
        public readonly array $references = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<int, string>  $requirementNames
     */
    public static function byKycRules(string $packageName, array $requirementNames): self
    {
        return new self(
            sprintf(
                '%s is named by %d active verification requirement(s): %s. Change or remove those rules first.',
                $packageName,
                count($requirementNames),
                implode(', ', $requirementNames),
            ),
            $requirementNames,
        );
    }

    public static function bySubscriptions(string $packageName, int $count): self
    {
        return new self(
            sprintf(
                '%s has %d account(s) subscribed to it. Move them to another package first.',
                $packageName,
                $count,
            ),
        );
    }
}
