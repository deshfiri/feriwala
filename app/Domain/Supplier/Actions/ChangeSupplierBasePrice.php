<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Sourcing\Policies\ProductSupplierPolicy;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Staff changing only the Supplier Base Price of an offer.
 *
 * The platform rate is carried over untouched; the change goes through
 * {@see SetSupplierOfferRates}, so it lands as a new effective-dated, audited
 * price version and never rewrites an allocation or payable already made from
 * an earlier one.
 */
class ChangeSupplierBasePrice
{
    public function __construct(protected SetSupplierOfferRates $rates) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function handle(User $actor, SupplierOffer $offer, Money $baseRate, string $reason): SupplierOffer
    {
        if (! ProductSupplierPolicy::canEditOffer($actor)) {
            throw new AuthorizationException('You may not change a Supplier Base Price.');
        }

        if (! $baseRate->isPositive()) {
            throw new InvalidArgumentException('The Supplier Base Price must be greater than zero.');
        }

        // The platform rate carries over; it only rises when the new base
        // price would otherwise sit above it, because an offer's platform rate
        // is never below its Supplier's. Staff see a margin warning for that.
        $platformRate = $offer->platform_rate->lessThan($baseRate) ? $baseRate : $offer->platform_rate;

        return $this->rates->handle($offer, $actor->id, $baseRate, $platformRate, $reason);
    }
}
