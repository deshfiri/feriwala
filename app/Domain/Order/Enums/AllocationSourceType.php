<?php

namespace App\Domain\Order\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * Where one order line is being fulfilled from.
 *
 * The two are genuinely different obligations, not two flavours of one: a
 * warehouse line draws on stock Feriwala already owns and owes nobody
 * anything, while a Supplier line creates a payable the moment it is
 * allocated. Keeping them as one enum — checked by the database — is what
 * stops a line from being half of each.
 */
enum AllocationSourceType: string
{
    use HasTranslatedLabel;

    case Warehouse = 'warehouse';
    case SupplierOffer = 'supplier_offer';

    protected static function statusLabelGroup(): string
    {
        return 'allocation_source';
    }

    /** Whether fulfilling from this source makes Feriwala owe a Supplier. */
    public function createsSupplierPayable(): bool
    {
        return $this === self::SupplierOffer;
    }
}
