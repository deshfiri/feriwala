<?php

namespace App\Domain\Website\Data;

use App\Domain\Website\Models\WebsiteCredential;

/**
 * A credential and its secret, at the one moment the secret may be seen
 * (contract §3.1, P5-17).
 *
 * Returned only by issuing and rotating. The plaintext is not stored anywhere
 * it can be read back from a screen afterwards, so this object is the whole of
 * a partner's chance to copy it.
 */
readonly class IssuedCredential
{
    public function __construct(
        public WebsiteCredential $credential,
        public string $secret,
    ) {}
}
