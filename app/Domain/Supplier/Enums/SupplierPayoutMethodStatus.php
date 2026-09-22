<?php

namespace App\Domain\Supplier\Enums;

/**
 * Whether a Supplier payout method may be used or picked as default.
 *
 * Never deleted (D25, P13-24) — a withdrawal already made against a method
 * keeps its own frozen snapshot, so archiving one changes nothing about a
 * past request; it only stops the method being offered for a new one.
 */
enum SupplierPayoutMethodStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }
}
