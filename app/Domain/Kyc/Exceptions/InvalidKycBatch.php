<?php

namespace App\Domain\Kyc\Exceptions;

use RuntimeException;

/**
 * A batch that cannot be published because an applicant could never complete it,
 * or would be asked for nothing (D30). Carries every problem found, not the
 * first, so an administrator fixes them in one pass.
 */
class InvalidKycBatch extends RuntimeException
{
    /**
     * @param  array<int, string>  $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}
