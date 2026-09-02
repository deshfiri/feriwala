<?php

namespace App\Domain\Account\Data;

use App\Domain\Account\Enums\AccountStatus;

/**
 * An intended account status change.
 *
 * `internalNote` and `userVisibleNote` are separate fields rather than one note
 * with a flag. Merging them is how a private review comment — "documents look
 * doctored, escalate" — ends up displayed to the account holder (§7.3).
 */
class AccountStatusChange
{
    public function __construct(
        public readonly AccountStatus $to,
        public readonly ?int $changedBy = null,
        public readonly ?string $reason = null,
        public readonly ?string $internalNote = null,
        public readonly ?string $userVisibleNote = null,
    ) {}

    /**
     * A change made by the system rather than a person — a scheduled deadline
     * check, a payment callback, a low-balance sweep.
     */
    public static function automatic(AccountStatus $to, string $reason): self
    {
        return new self(to: $to, changedBy: null, reason: $reason);
    }
}
