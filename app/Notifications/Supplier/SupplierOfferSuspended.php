<?php

namespace App\Notifications\Supplier;

use App\Domain\Supplier\Models\SupplierOffer;

class SupplierOfferSuspended extends SupplierLifecycleNotification
{
    public function __construct(
        protected readonly SupplierOffer $offer,
        ?string $note = null,
    ) {
        parent::__construct($note);
    }

    protected function eventKey(): string
    {
        return 'supplier.offer_suspended';
    }

    protected function extra(): array
    {
        return ['offer_reference' => $this->offer->reference, 'offer_id' => $this->offer->public_id];
    }
}
