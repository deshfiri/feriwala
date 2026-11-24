<?php

namespace App\Domain\Order\Exceptions;

use RuntimeException;

/**
 * An order revision the rules do not allow, refused before anything is kept (D38).
 *
 * Each refusal carries a stable `refusal` code — what the screen keys its
 * explanation on — and a message in the reader's language that says what to
 * do instead.
 */
class OrderRevisionRefused extends RuntimeException
{
    public function __construct(public readonly string $refusal, string $message, public readonly string $field = 'revision')
    {
        parent::__construct($message);
    }

    public static function because(string $refusal, string $field = 'revision'): self
    {
        return new self($refusal, __("orders.revision.refused.{$refusal}"), $field);
    }

    /** Someone else revised the order since this one was prepared. */
    public static function changed(): self
    {
        return self::because('revision_changed');
    }

    public static function nothingChanged(): self
    {
        return self::because('nothing_changed');
    }

    public static function busy(): self
    {
        return self::because('busy');
    }
}
