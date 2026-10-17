<?php

namespace App\Domain\Order\Queries;

use App\Domain\Inventory\PartnerStockReservations;
use App\Domain\Order\Data\WebsiteOrderLine;
use App\Domain\Order\Data\WebsiteOrderQuote;
use App\Domain\Website\Models\Website;

/**
 * Whether each line of a quote could be reserved if it were ordered now —
 * read-only, holding nothing (P2 H2).
 *
 * The same decision order placement makes: a storefront order is filled from the
 * units the website's owner bought, of the exact Variation, whole — never from
 * central stock, a Supplier, or another Partner (D34).
 *
 * Advisory only. Stock can move between this answer and an order; the
 * reservation at placement is the binding check. Bounded by the quote's line
 * count, which the request already caps.
 */
class WebsiteOrderReservability
{
    public function __construct(
        protected PartnerStockReservations $partnerStock,
    ) {}

    /**
     * @return array<int, bool> keyed by line position
     */
    public function forQuote(Website $website, WebsiteOrderQuote $quote): array
    {
        $answers = [];

        foreach ($quote->lines as $position => $line) {
            $answers[$position] = $this->line($website, $line);
        }

        return $answers;
    }

    protected function line(Website $website, WebsiteOrderLine $line): bool
    {
        return $this->partnerStock->canHold(
            $website->businessAccount,
            $line->product->id,
            $line->variant?->id,
            $line->quantity,
        );
    }
}
