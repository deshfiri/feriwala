<?php

namespace App\Domain\Courier\Data;

use App\Integrations\Courier\Steadfast\SteadfastStatus;

/**
 * What applying one Steadfast status did, so the caller can tell staff exactly
 * once and a repeat can be told apart from news.
 */
final class SteadfastStatusApplied
{
    public function __construct(
        public readonly bool $changed,
        public readonly ?SteadfastStatus $status,
        public readonly bool $unknown,
        public readonly bool $newlyFlagged,
        public readonly bool $courierMoved,
    ) {}

    public static function unchanged(?SteadfastStatus $status): self
    {
        return new self(false, $status, false, false, false);
    }
}
