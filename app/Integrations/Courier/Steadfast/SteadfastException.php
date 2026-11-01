<?php

namespace App\Integrations\Courier\Steadfast;

use RuntimeException;

/**
 * A Steadfast call that gave no usable answer. Carries only the kind and the
 * HTTP status -- never a body, a header or a credential.
 */
class SteadfastException extends RuntimeException
{
    public function __construct(
        public readonly SteadfastFailureKind $kind,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($kind->message());
    }
}
