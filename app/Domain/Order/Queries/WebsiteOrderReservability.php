<?php

namespace App\Domain\Order\Queries;

use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Data\WebsiteOrderLine;
use App\Domain\Order\Data\WebsiteOrderQuote;
use App\Domain\Supplier\Actions\AllocateSupplierOrderLine;
use App\Domain\Supplier\Exceptions\SupplierAllocationRefused;
use App\Domain\Supplier\Queries\ResolvePreferredOffer;
use App\Domain\Website\Models\Website;

/**
 * Whether each line of a quote could be reserved if it were ordered now —
 * read-only, holding nothing (P2 H2).
 *
 * The same decision order placement makes ({@see AllocateSupplierOrderLine}):
 * a product with a preferred Supplier offer is served from that offer or not
 * at all (never a fallback, D25); anything else from central stock, which must
 * come whole from the account's own allocation or one active warehouse
 * ({@see StockReservations::canReserve()}). Which Supplier, or why its offer
 * could not serve, is never said.
 *
 * Advisory only. Stock can move between this answer and an order; the
 * reservation at placement is the binding check. Bounded by the quote's line
 * count, which the request already caps.
 */
class WebsiteOrderReservability
{
    public function __construct(
        protected ResolvePreferredOffer $offers,
        protected StockReservations $reservations,
    ) {}

    /**
     * @return array<int, bool> keyed by line position
     */
    public function forQuote(Website $website, WebsiteOrderQuote $quote): array
    {
        $answers = [];

        foreach ($quote->lines as $position => $line) {
            $answers[$position] = $this->line($website, $quote, $line);
        }

        return $answers;
    }

    protected function line(Website $website, WebsiteOrderQuote $quote, WebsiteOrderLine $line): bool
    {
        try {
            $allocation = $this->offers->resolve($line->product, $line->variant, $line->quantity, $quote->currency);
        } catch (SupplierAllocationRefused) {
            return false;
        }

        if ($allocation !== null) {
            return true;
        }

        return $this->reservations->canReserve($line->product, $line->variant, $line->quantity, $website->businessAccount);
    }
}
