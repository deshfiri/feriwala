<?php

namespace App\Notifications\Supplier;

use App\Domain\Supplier\Models\SupplierProductListing;

class SupplierListingApproved extends SupplierLifecycleNotification
{
    public function __construct(
        protected readonly SupplierProductListing $listing,
        ?string $note = null,
    ) {
        parent::__construct($note);
    }

    protected function eventKey(): string
    {
        return 'supplier.listing_approved';
    }

    protected function extra(): array
    {
        return ['listing_reference' => $this->listing->reference, 'listing_id' => $this->listing->public_id];
    }
}
