<?php

namespace App\Domain\Kyc\Exceptions;

use InvalidArgumentException;

/**
 * A staff upload the round refused, naming which target it was, so a screen
 * that sent several files can put the message beside the right one.
 */
class KycUploadRefused extends InvalidArgumentException
{
    public function __construct(public readonly string $target, string $message)
    {
        parent::__construct($message);
    }
}
