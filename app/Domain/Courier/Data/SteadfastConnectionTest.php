<?php

namespace App\Domain\Courier\Data;

use App\Integrations\Courier\Steadfast\SteadfastFailureKind;
use App\Support\Money\Money;

/**
 * What a Test connection found, safe to show: whether it worked, why not, and
 * the account balance. Never a raw response, a key or a secret.
 */
final class SteadfastConnectionTest
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?SteadfastFailureKind $failure = null,
        public readonly ?Money $balance = null,
    ) {}
}
