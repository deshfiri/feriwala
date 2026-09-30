<?php

namespace App\Notifications\Supplier;

use App\Domain\Supplier\Models\SupplierProductListingLot;

class SupplierListingLotSubmitted extends SupplierLifecycleNotification
{
    public function __construct(
        protected readonly SupplierProductListingLot $lot,
        ?string $note = null,
    ) {
        parent::__construct($note);
    }

    protected function eventKey(): string
    {
        return 'supplier.listing_lot_submitted';
    }

    protected function extra(): array
    {
        return [
            'lot_reference' => $this->lot->reference,
            'lot_id' => $this->lot->public_id,
            'item_count' => $this->lot->items()->count(),
        ];
    }
}
