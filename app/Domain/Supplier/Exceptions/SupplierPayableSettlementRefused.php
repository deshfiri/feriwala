<?php

namespace App\Domain\Supplier\Exceptions;

use App\Domain\Supplier\Models\SupplierPayable;
use RuntimeException;

/**
 * Why a Supplier payable could not be settled (D25, P13-23).
 *
 * Deliberately not thrown for a payable that is already settled — that is
 * idempotent success, not a refusal, and {@see
 * \App\Domain\Supplier\Actions\SettleSupplierPayable} returns it as such.
 */
class SupplierPayableSettlementRefused extends RuntimeException
{
    public static function notEligible(SupplierPayable $payable): self
    {
        return new self(sprintf(
            'Payable %s is %s, not eligible, and cannot be settled.',
            $payable->reference,
            $payable->status->label(),
        ));
    }

    public static function notEarned(SupplierPayable $payable): self
    {
        return new self(sprintf(
            'Payable %s is missing its delivery or payment-settled record and cannot be settled.',
            $payable->reference,
        ));
    }
}
