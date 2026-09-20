<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\StorefrontRequest;
use Carbon\CarbonImmutable;

/**
 * Forget answers to storefront writes once nobody may replay them any more
 * (contract §4.7, P5-21).
 *
 * The contract's replay window is 24 hours. These are kept a good deal longer —
 * long enough that a partner asking "what did you answer us on Tuesday?" has a
 * factual answer — and then go, because they hold whole request bodies of
 * somebody else's customers and keeping those for ever is not something anybody
 * asked for.
 *
 * The orders themselves are untouched, and so is the guarantee: a storefront
 * reference is unique per website for as long as the order exists, so a replay
 * after this has run still cannot become a second order.
 */
class PruneStorefrontRequests
{
    public const KEEP_DAYS = 30;

    /**
     * @return int how many were forgotten
     */
    public function handle(?CarbonImmutable $before = null): int
    {
        $before ??= CarbonImmutable::now()->subDays(self::KEEP_DAYS);

        return StorefrontRequest::query()->where('created_at', '<', $before)->delete();
    }
}
