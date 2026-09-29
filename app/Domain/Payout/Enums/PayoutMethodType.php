<?php

namespace App\Domain\Payout\Enums;

use App\Domain\Payout\Actions\SavePayoutMethod;

/**
 * How a payout method pays out — shared by every owner kind (D25, P13-24;
 * generalized from the Supplier-only `SupplierPayoutMethodType`).
 */
enum PayoutMethodType: string
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

    public function requiresBankBranch(): bool
    {
        return $this === self::BankAccount;
    }

    /**
     * The field names {@see SavePayoutMethod} expects inside the encrypted
     * `details` blob, and validates against, for this type. Which bank and
     * branch a bank-account method uses are real FK columns, never inside
     * this blob — which bank/branch a method uses is not itself sensitive.
     *
     * @return array<int, string>
     */
    public function detailFields(): array
    {
        return match ($this) {
            self::BankAccount => ['account_holder_name', 'account_number', 'account_type'],
            self::Bkash, self::Nagad => ['account_holder_name', 'account_number'],
        };
    }

    /**
     * Which detail field to derive `last_four` and the fingerprint from.
     */
    public function numberField(): string
    {
        return 'account_number';
    }
}
