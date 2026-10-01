<?php

namespace App\Notifications\Supplier;

use App\Domain\Supplier\Actions\ExpireOverdueFulfilmentCommitments;
use App\Domain\Supplier\Models\SupplierFulfilmentCommitment;

/**
 * A Supplier's own fulfilment commitment was cancelled because the
 * confirmation deadline passed without a response
 * ({@see ExpireOverdueFulfilmentCommitments},
 * Advanced Order Management batch, Commit 2).
 */
class SupplierFulfilmentCommitmentExpired extends SupplierLifecycleNotification
{
    public function __construct(
        protected readonly SupplierFulfilmentCommitment $commitment,
        ?string $note = null,
    ) {
        parent::__construct($note);
    }

    protected function eventKey(): string
    {
        return 'supplier.fulfilment_commitment_expired';
    }

    protected function extra(): array
    {
        return [
            'commitment_reference' => $this->commitment->reference,
            'commitment_id' => $this->commitment->public_id,
        ];
    }
}
