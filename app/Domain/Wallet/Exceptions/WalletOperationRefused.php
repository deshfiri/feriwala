<?php

namespace App\Domain\Wallet\Exceptions;

use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Support\Money\Money;
use RuntimeException;

/**
 * A wallet operation that must not happen (§23, §36.1).
 *
 * Every one of these is refused **before** anything is written, so a refusal
 * never leaves a half-posted transaction or an orphan reservation behind.
 */
class WalletOperationRefused extends RuntimeException
{
    public static function insufficientBalance(Money $requested, Money $available): self
    {
        return new self(sprintf(
            'The wallet has %s available and %s was requested.',
            $available->format(),
            $requested->format(),
        ));
    }

    /**
     * Cross-currency posting fails loudly rather than converting (D4).
     *
     * No exchange-rate accounting exists in v1, so a conversion here would be a
     * rate nobody agreed applied to somebody's money.
     */
    public static function currencyMismatch(string $wallet, string $amount): self
    {
        return new self(sprintf(
            'This wallet is in %s and the amount is in %s. There is no conversion.',
            $wallet,
            $amount,
        ));
    }

    public static function notPositive(): self
    {
        return new self('A wallet movement must be for a positive amount.');
    }

    public static function reasonRequired(LedgerTransactionType $type): self
    {
        return new self(sprintf('A %s must say why.', mb_strtolower($type->label())));
    }

    public static function actorRequired(LedgerTransactionType $type): self
    {
        return new self(sprintf('A %s must record who made it.', mb_strtolower($type->label())));
    }

    public static function directionRequired(LedgerTransactionType $type): self
    {
        return new self(sprintf(
            'A %s can go either way, so the direction has to be stated.',
            mb_strtolower($type->label()),
        ));
    }

    public static function alreadySettled(string $reference): self
    {
        return new self("Transaction {$reference} has already been settled or released.");
    }
}
