<?php

namespace App\Domain\Supplier\Data;

use App\Domain\Supplier\Actions\SupplierWalletService;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerDirection;

/**
 * Everything a Supplier ledger posting needs beyond the amount (D25, P13-23).
 *
 * Mirrors {@see PostingContext} at the smaller scale
 * {@see SupplierWalletService} needs.
 */
readonly class SupplierPostingContext
{
    public function __construct(
        /** Where this came from: 'payable_settlement', 'withdrawal', 'manual'. */
        public string $source,

        /** What the Supplier reads on their own statement. */
        public string $description,

        /** Unique across Supplier ledger entries, so a retried command posts once. */
        public ?string $idempotencyKey = null,

        /** Required for a reversal or a manual adjustment. */
        public ?string $reason = null,

        /** Staff-only. Never reaches a Supplier's screen. */
        public ?string $internalNote = null,

        /** Who did it, where a person did — required for a manual adjustment. */
        public ?int $actorId = null,

        public ?int $supplierPayableId = null,
        public ?int $supplierPayableReversalId = null,
        public ?int $supplierWithdrawalId = null,

        /** Only for the manual adjustment type, which can go either way. */
        public ?LedgerDirection $direction = null,

        /** The entry this one puts right, if any. */
        public ?int $correctsLedgerEntryId = null,
    ) {}
}
