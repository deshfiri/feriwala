<?php

namespace App\Domain\Order\Enums;

use App\Support\Status\HasTranslatedLabel;

/**
 * Who a case, a charge or a loss is attributed to (D35).
 *
 * Only two: Banij, or the Partner. A fault on the customer's side is the
 * Partner's to settle with their customer — Banij records the Partner's
 * liability and creates no debt for the customer.
 */
enum ReturnParty: string
{
    use HasTranslatedLabel;

    case Partner = 'partner';
    case Banij = 'banij';

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

    protected static function statusLabelGroup(): string
    {
        return 'return_party';
    }
}
