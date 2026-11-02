<?php

namespace App\Domain\Order\Exceptions;

use RuntimeException;

/**
 * An order whose platform receivable cannot be worked out safely (D36).
 *
 * Refused rather than approximated: an undefined line is never counted as zero,
 * and a figure that does not reconcile with the order is never adjusted until it
 * does. The messages carry order and line numbers only, never a customer detail.
 */
class PlatformReceivableRefused extends RuntimeException
{
    public static function notAStoreOrder(): self
    {
        return new self('Only a Store Order has a platform receivable.');
    }

    public static function notNonConditional(): self
    {
        return new self('Only a Non-Conditional account sells with a platform receivable.');
    }

    public static function noLines(): self
    {
        return new self('An order with no lines has no platform receivable.');
    }

    public static function undefinedLine(int $lineNumber): self
    {
        return new self("Line {$lineNumber} has no frozen platform amount or product cost, so Feriwala's share of it is undefined.");
    }

    public static function currencyMismatch(): self
    {
        return new self('Every figure of a platform receivable must be in the order\'s currency.');
    }

    public static function doesNotReconcile(string $what): self
    {
        return new self("The order's frozen {$what} does not match the figures the receivable is built from.");
    }

    public static function disagreesWithOrder(): self
    {
        return new self('The platform receivable does not match the canonical recoverable amount of the order\'s lines.');
    }
}
