<?php

namespace App\Domain\Supplier\Enums;

use App\Domain\Supplier\Actions\SavePayoutMethod;

/**
 * How a Supplier is paid out (D25, P13-24).
 */
enum SupplierPayoutMethodType: string
{
    case BankAccount = 'bank_account';
    case Bkash = 'bkash';
    case Nagad = 'nagad';

    public function label(): string
    {
        return match ($this) {
            self::BankAccount => 'Bank account',
            self::Bkash => 'bKash',
            self::Nagad => 'Nagad',
        };
    }

    /**
     * The field names {@see SavePayoutMethod}
     * expects in `details`, and validates against, for this type.
     *
     * @return array<int, string>
     */
    public function detailFields(): array
    {
        return match ($this) {
            self::BankAccount => ['bank_name', 'account_name', 'account_number', 'routing_number', 'branch'],
            self::Bkash, self::Nagad => ['account_name', 'account_number'],
        };
    }

    /**
     * Which detail field to derive `last_four` from.
     */
    public function numberField(): string
    {
        return 'account_number';
    }
}
