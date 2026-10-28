<?php

namespace App\Domain\Order\Enums;

use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Support\Status\HasTranslatedLabel;

/**
 * What a case's money is for (D35).
 *
 * Each kind posts under its own ledger transaction type, so a Partner's
 * statement says *why* money left: a return, a replacement delivery, a damaged
 * item, or sending a damaged item back to them.
 */
enum ReturnChargeKind: string
{
    use HasTranslatedLabel;

    /** Half of the original delivery charge, for the parcel coming back. */
    case ReturnCharge = 'return_charge';

    /** The one new delivery an exchange sends. */
    case ExchangeDelivery = 'exchange_delivery';

    /** The approved loss on a damaged item. */
    case DamageLoss = 'damage_loss';

    /** Delivering a damaged item to the Partner who asked for it. */
    case DamageDelivery = 'damage_delivery';

    public function ledgerType(): LedgerTransactionType
    {
        return match ($this) {
            self::ReturnCharge => LedgerTransactionType::ReturnChargeDebit,
            self::ExchangeDelivery => LedgerTransactionType::ExchangeDeliveryDebit,
            self::DamageLoss => LedgerTransactionType::DamageLossDebit,
            self::DamageDelivery => LedgerTransactionType::DamageDeliveryDebit,
        };
    }

    protected static function statusLabelGroup(): string
    {
        return 'return_charge_kind';
    }
}
