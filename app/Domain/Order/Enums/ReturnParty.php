<?php

namespace App\Domain\Order\Enums;

/**
 * Who a case, a charge or a loss is attributed to (D35).
 *
 * Only two: Banij, or the Partner. A fault on the customer's side is the
 * Partner's to settle with their customer — Banij records the Partner's
 * liability and creates no debt for the customer.
 */
enum ReturnParty: string
{
    case Partner = 'partner';
    case Banij = 'banij';

    public function label(): string
    {
        return match ($this) {
            self::Partner => 'Partner',
            self::Banij => 'Banij',
        };
    }

    public function isPartner(): bool
    {
        return $this === self::Partner;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $party) => $party->value, self::cases());
    }
}
