<?php

namespace App\Domain\Supplier\Exceptions;

use App\Domain\Supplier\Enums\SupplierLedgerEntryType;
use App\Domain\Wallet\Exceptions\WalletOperationRefused;
use App\Support\Money\Money;
use RuntimeException;

/**
 * Mirrors {@see WalletOperationRefused} for the
 * Supplier wallet (D25, P13-23/P13-24).
 */
class SupplierWalletOperationRefused extends RuntimeException
{
    public static function insufficientBalance(Money $requested, Money $available): self
    {
        return new self(sprintf(
            'This Supplier wallet has %s available and %s was requested.',
            $available->format(),
            $requested->format(),
        ));
    }

    public static function currencyMismatch(string $wallet, string $amount): self
    {
        return new self(sprintf(
            'This Supplier wallet is in %s and the amount is in %s. There is no conversion.',
            $wallet,
            $amount,
        ));
    }

    public static function notPositive(): self
    {
        return new self('A Supplier wallet movement must be for a positive amount.');
    }

    public static function reasonRequired(SupplierLedgerEntryType $type): self
    {
        return new self(sprintf('A %s must say why.', mb_strtolower($type->label())));
    }

    public static function actorRequired(SupplierLedgerEntryType $type): self
    {
        return new self(sprintf('A %s must record who made it.', mb_strtolower($type->label())));
    }

    /**
     * A credit nothing maps to a kind of money has to be told which: whether
     * money can leave the platform is a decision, never a default.
     */
    public static function balanceTypeRequired(SupplierLedgerEntryType $type): self
    {
        return new self(sprintf(
            'A %s must say whether the money is platform credit or withdrawable.',
            mb_strtolower($type->label()),
        ));
    }

    public static function insufficientPlatformCredit(Money $requested, Money $available): self
    {
        return new self(sprintf(
            'There is %s of platform credit available to convert and %s was requested.',
            $available->format(),
            $requested->format(),
        ));
    }

    public static function notPermitted(): self
    {
        return new self('You are not permitted to do this.');
    }

    public static function directionRequired(SupplierLedgerEntryType $type): self
    {
        return new self(sprintf(
            'A %s can go either way, so the direction has to be stated.',
            mb_strtolower($type->label()),
        ));
    }
}
