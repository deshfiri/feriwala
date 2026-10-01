<?php

namespace App\Domain\Supplier\Enums;

use App\Support\StateMachine\TransitionableState;

/**
 * How a Supplier can actually fulfil what they're listing (Supplier Bulk
 * Product Listing batch).
 *
 * A declared fact about the offer, not a lifecycle -- unlike
 * {@see ListingStatus}/{@see LotStatus} this is a plain enum, never a
 * {@see TransitionableState}. Its only job is to
 * keep "no confirmed stock" from ever being read as "zero stock" or "an
 * unlimited guarantee": {@see buyerFacingLabel()} is the one place that
 * translation happens, so a real ready-stock number and an honest "ask the
 * Supplier" label can never be confused for each other by an inventory
 * figure that looks the same either way.
 */
enum SupplyMode: string
{
    case ReadyStock = 'ready_stock';
    case OnDemand = 'on_demand';
    case PreOrder = 'pre_order';

    public function label(): string
    {
        return match ($this) {
            self::ReadyStock => 'Ready stock',
            self::OnDemand => 'On demand',
            self::PreOrder => 'Pre-order',
        };
    }

    /**
     * What a buyer or staff should read where a stock figure would
     * otherwise go. `null` for ready stock means "show the real number
     * instead" -- this method is never asked to invent one.
     */
    public function buyerFacingLabel(): ?string
    {
        return match ($this) {
            self::ReadyStock => null,
            self::OnDemand => 'Available on demand',
            self::PreOrder => 'Subject to Supplier confirmation',
        };
    }

    public function requiresStaffConfirmationAtAllocation(): bool
    {
        return $this !== self::ReadyStock;
    }
}
