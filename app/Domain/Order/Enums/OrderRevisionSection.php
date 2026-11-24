<?php

namespace App\Domain\Order\Enums;

use App\Domain\Access\Enums\PermissionAction;

/**
 * The parts of an order a revision may touch (D38), each answering to the
 * permission that may change it.
 */
enum OrderRevisionSection: string
{
    case Customer = 'customer';
    case Addresses = 'addresses';
    case Items = 'items';
    case Pricing = 'pricing';
    case Delivery = 'delivery';
    case PaymentMethod = 'payment_method';
    case Note = 'note';

    /**
     * The `order.*` permission that may change this part.
     *
     * A delivery charge follows the address it is priced from, so a new
     * district needs the address permission; overriding the charge itself is a
     * financial correction. The internal note is part of every revision and
     * needs only the permission of whatever else the revision changes.
     */
    public function permission(): ?PermissionAction
    {
        return match ($this) {
            self::Customer => PermissionAction::EditCustomer,
            self::Addresses => PermissionAction::EditAddress,
            self::Items => PermissionAction::ReviseItems,
            self::Pricing, self::Delivery, self::PaymentMethod => PermissionAction::ReviseFinancials,
            self::Note => null,
        };
    }
}
