<?php

namespace App\Domain\Wallet\Data;

use App\Domain\Wallet\Enums\LedgerDirection;

/**
 * Everything a posting needs to know beyond the amount (§23.2).
 *
 * One object rather than nine arguments, because §23.2's audit trail asks for
 * the source, the related entity, the actor, the reason and the idempotency
 * identity together — and a signature that long is one where two arguments
 * eventually get swapped.
 */
readonly class PostingContext
{
    public function __construct(
        /** Where this came from: 'payment', 'manual', 'system', 'order'. */
        public string $source,

        /** What the account holder reads on their statement. */
        public string $description,

        /**
         * The command's identity. Unique across wallet transactions and ledger
         * entries, so the same command retried posts once (§36.1).
         */
        public ?string $idempotencyKey = null,

        /** Required for every correction (§23.2). */
        public ?string $reason = null,

        /** Staff-only. Never reaches an account member's screen. */
        public ?string $internalNote = null,

        /** Who did it, where a person did. */
        public ?int $actorId = null,

        /** Who authorised it, where that is a separate person. */
        public ?int $approvedBy = null,

        public ?int $paymentId = null,
        public ?int $userPackageId = null,
        public ?int $userId = null,

        /** Only for the correction types, which can go either way. */
        public ?LedgerDirection $direction = null,

        /** The entry this one puts right (§23.2). */
        public ?int $correctsLedgerEntryId = null,
    ) {}
}
